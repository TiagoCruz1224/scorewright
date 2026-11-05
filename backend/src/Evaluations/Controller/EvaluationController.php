<?php

declare(strict_types=1);

namespace App\Evaluations\Controller;

use App\Datasets\Entity\Run;
use App\Datasets\Repository\DatasetVersionRepository;
use App\Datasets\Repository\RunRepository;
use App\Evaluations\Checks\RunAutomaticChecks;
use App\Evaluations\Dto\CreateEvaluationRequest;
use App\Evaluations\Entity\CheckJob;
use App\Evaluations\Entity\CheckJobStatus;
use App\Evaluations\Entity\Criterion;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Repository\EvaluationItemRepository;
use App\Evaluations\Repository\EvaluationRepository;
use App\Evaluations\Repository\RubricRepository;
use App\Http\ApiProblem;
use App\Http\PageQuery;
use App\Projects\ProjectAccess;
use App\Projects\Security\ProjectVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Evaluations (architecture §5). Members read them; owners create, close and delete them. No route here returns an
 * answer: the scoring routes do (ScoringController), and in blind mode they never say which run an answer is from.
 */
final class EvaluationController
{
    private const PROJECT = ['projectId' => Requirement::UUID];
    private const EVALUATION = ['projectId' => Requirement::UUID, 'evaluationId' => Requirement::UUID];
    private const LETTERS = 'ABCDE';

    public function __construct(
        private readonly EvaluationRepository $evaluations,
        private readonly EvaluationItemRepository $items,
        private readonly DatasetVersionRepository $versions,
        private readonly RunRepository $runs,
        private readonly RubricRepository $rubrics,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/projects/{projectId}/evaluations', name: 'evaluation_list', methods: ['GET'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function list(ProjectAccess $projectAccess, #[MapQueryString] PageQuery $query = new PageQuery()): JsonResponse
    {
        $page = $this->evaluations->findPageInProject($projectAccess->project->getId(), $query->page, $query->perPage);
        $progress = $this->evaluations->progress($page['items']);
        $runs = $this->evaluations->runs($page['items']);

        return new JsonResponse([
            'items' => array_map(static fn (Evaluation $evaluation): array => self::evaluationJson($evaluation, $progress, $runs), $page['items']),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'total' => $page['total'],
        ]);
    }

    /**
     * Creates the evaluation with its items, locks the rubric and, when the rubric has automatic criteria, queues a
     * check job, all in one transaction. The job is dispatched to the worker after the commit, so the worker always
     * finds it (architecture §7).
     */
    #[Route('/api/projects/{projectId}/evaluations', name: 'evaluation_create', methods: ['POST'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function create(ProjectAccess $projectAccess, #[MapRequestPayload(acceptFormat: 'json')] CreateEvaluationRequest $body): JsonResponse
    {
        $projectId = $projectAccess->project->getId();
        $errors = [];
        // Another project's version, run or rubric gets the same answer as one that does not exist.
        $version = Uuid::isValid($body->datasetVersion) ? $this->versions->findInProject($projectId, Uuid::fromString($body->datasetVersion)) : null;
        if (null === $version) {
            $errors[] = ['field' => 'datasetVersion', 'code' => 'not_found', 'message' => 'This project has no dataset version with this id. Choose one of its versions.'];
        }
        $runs = [];
        foreach (array_values($body->runs) as $index => $runId) {
            $run = \is_string($runId) && Uuid::isValid($runId) ? $this->runs->findInProject($projectId, Uuid::fromString($runId)) : null;
            $error = match (true) {
                null === $run => ['not_found', 'This project has no run with this id. Choose one of its runs.'],
                isset($runs[$run->getId()->toRfc4122()]) => ['duplicate_run', 'This run is already in the evaluation. Choose each run once.'],
                null !== $version && $run->getDatasetVersion() !== $version => ['other_dataset_version', \sprintf('This run answers another dataset version. Choose runs of version %d of %s.', $version->getNumber(), $version->getDataset()->getName())],
                default => null,
            };
            if (null !== $error) {
                $errors[] = ['field' => "runs[$index]", 'code' => $error[0], 'message' => $error[1]];
            } elseif (null !== $run) {
                $runs[$run->getId()->toRfc4122()] = $run;
            }
        }
        $rubric = Uuid::isValid($body->rubric) ? $this->rubrics->findInProject($projectId, Uuid::fromString($body->rubric)) : null;
        if (null === $rubric) {
            $errors[] = ['field' => 'rubric', 'code' => 'not_found', 'message' => 'This project has no rubric with this id. Choose one of its rubrics.'];
        }
        if ([] !== $errors || null === $rubric) {
            throw ApiProblem::validation($errors);
        }
        if ($this->evaluations->nameIsTaken($projectId, $body->name)) {
            throw self::nameTaken();
        }

        try {
            [$evaluation, $job] = $this->entityManager->wrapInTransaction(function () use ($projectAccess, $version, $rubric, $body, $runs): array {
                // FOR UPDATE pairs with RubricController::update: an edit commits before this, or sees the lock.
                $this->entityManager->refresh($rubric, LockMode::PESSIMISTIC_WRITE);
                $rubric->lock();
                $evaluation = new Evaluation($projectAccess->project, $version, $rubric, $body->name, $body->blind);
                $this->entityManager->persist($evaluation);
                foreach (array_values($runs) as $index => $run) {
                    $this->entityManager->persist(new EvaluationRun($evaluation, $run, self::LETTERS[$index]));
                }
                $this->entityManager->flush();

                $itemCount = $this->items->createFor($evaluation, array_values($runs));
                $job = null;
                if (self::hasAutomaticCriteria($rubric)) {
                    $job = new CheckJob($evaluation, $itemCount);
                    $this->entityManager->persist($job);
                }

                return [$evaluation, $job];
            });
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken(); // created by a concurrent request
        }
        if (null !== $job) {
            $this->dispatch($job);
        }

        return new JsonResponse($this->detailsJson($evaluation), Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}', name: 'evaluation_show', methods: ['GET'], requirements: self::EVALUATION)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $evaluationId): JsonResponse
    {
        return new JsonResponse($this->detailsJson($this->findEvaluation($projectAccess, $evaluationId)));
    }

    /**
     * Closing is final (§12, decision 1), so the scores stop changing: closing again answers 409 evaluation_closed, and
     * so does closing while a check job is queued or running (409 check_job_running). A failed job does not stop it.
     */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/close', name: 'evaluation_close', methods: ['POST'], requirements: self::EVALUATION)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function close(ProjectAccess $projectAccess, string $evaluationId): JsonResponse
    {
        $evaluation = $this->findEvaluation($projectAccess, $evaluationId);
        $this->entityManager->wrapInTransaction(function () use ($evaluation): void {
            // FOR UPDATE pairs with runAutomaticChecks: a re-run queues its job before this, or sees the evaluation closed.
            $this->entityManager->refresh($evaluation, LockMode::PESSIMISTIC_WRITE);
            if ($evaluation->isClosed()) {
                throw self::closed();
            }
            if ($this->hasActiveCheckJob($evaluation)) {
                throw self::checkJobRunning();
            }
            $evaluation->close();
        });

        return new JsonResponse($this->detailsJson($evaluation));
    }

    /**
     * Runs the automatic checks again (owner, open evaluations only: closing is final, so the scores no longer change):
     * - a new job, unless one is queued: that one is dispatched again, which also recovers a job whose dispatch failed
     *   (the handler does a job once, however many messages name it);
     * - a running job answers 409 check_job_running; a rubric without automatic criteria, 422 no_automatic_criteria.
     */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/automatic-checks', name: 'evaluation_automatic_checks', methods: ['POST'], requirements: self::EVALUATION)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function runAutomaticChecks(ProjectAccess $projectAccess, string $evaluationId): JsonResponse
    {
        $evaluation = $this->findEvaluation($projectAccess, $evaluationId);
        if (!self::hasAutomaticCriteria($evaluation->getRubric())) {
            throw new ApiProblem(422, 'no_automatic_criteria', 'The rubric of this evaluation has no automatic criteria to check');
        }

        try {
            $job = $this->entityManager->wrapInTransaction(function () use ($evaluation): CheckJob {
                // FOR UPDATE pairs with close: no job is queued once the evaluation is closed.
                $this->entityManager->refresh($evaluation, LockMode::PESSIMISTIC_WRITE);
                if ($evaluation->isClosed()) {
                    throw self::closed();
                }
                $job = $this->evaluations->latestCheckJob($evaluation);
                if (CheckJobStatus::Running === $job?->getStatus()) {
                    throw self::checkJobRunning();
                }
                if (null === $job || CheckJobStatus::Queued !== $job->getStatus()) {
                    $job = new CheckJob($evaluation, $this->items->count(['evaluation' => $evaluation]));
                    $this->entityManager->persist($job);
                }

                return $job;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::checkJobRunning(); // queued by a concurrent request
        }
        $this->dispatch($job);

        return new JsonResponse($this->detailsJson($evaluation), Response::HTTP_ACCEPTED);
    }

    /** Deletes the evaluation with its items, scores, comments and check jobs (database cascade). The rubric stays locked. */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}', name: 'evaluation_delete', methods: ['DELETE'], requirements: self::EVALUATION)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function delete(ProjectAccess $projectAccess, string $evaluationId): Response
    {
        $this->entityManager->remove($this->findEvaluation($projectAccess, $evaluationId));
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    public static function closed(): ApiProblem
    {
        return new ApiProblem(409, 'evaluation_closed', 'The evaluation is closed: it takes no more scores or comments, and it is never reopened');
    }

    /** A dispatch that fails leaves the job queued, and POST …/automatic-checks dispatches it again. */
    private function dispatch(CheckJob $job): void
    {
        try {
            $this->bus->dispatch(new RunAutomaticChecks($job->getId()->toRfc4122()));
        } catch (MessengerException $exception) {
            $this->logger->error('The check job {job} could not be dispatched; it stays queued.', ['job' => $job->getId()->toRfc4122(), 'exception' => $exception]);
        }
    }

    private static function checkJobRunning(): ApiProblem
    {
        return new ApiProblem(409, 'check_job_running', 'The automatic checks of this evaluation are queued or running. Wait for them to finish');
    }

    private function hasActiveCheckJob(Evaluation $evaluation): bool
    {
        return \in_array($this->evaluations->latestCheckJob($evaluation)?->getStatus(), [CheckJobStatus::Queued, CheckJobStatus::Running], true);
    }

    private function findEvaluation(ProjectAccess $projectAccess, string $evaluationId): Evaluation
    {
        // The same answer as for a project you are not a member of.
        return $this->evaluations->findInProject($projectAccess->project->getId(), Uuid::fromString($evaluationId))
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');
    }

    private static function hasAutomaticCriteria(Rubric $rubric): bool
    {
        foreach ($rubric->getCriteria() as $criterion) {
            if (CriterionKind::Automatic === $criterion->getData()->kind) {
                return true;
            }
        }

        return false;
    }

    private static function nameTaken(): ApiProblem
    {
        return ApiProblem::validation([['field' => 'name', 'code' => 'name_taken', 'message' => 'The project already has an evaluation with this name. Choose another name.']]);
    }

    /**
     * The evaluation with its check job and the scores given on each criterion ("Progress by criterion"). No answers
     * (§5, "Run data and blind mode").
     *
     * @return array<string, mixed>
     */
    private function detailsJson(Evaluation $evaluation): array
    {
        $job = $this->evaluations->latestCheckJob($evaluation);
        $scored = $this->evaluations->scoresByCriterion($evaluation);

        return self::evaluationJson($evaluation, $this->evaluations->progress([$evaluation]), $this->evaluations->runs([$evaluation])) + [
            'checkJob' => null === $job ? null : [
                'status' => $job->getStatus()->value,
                'processed' => $job->getProcessed(),
                'total' => $job->getTotal(),
                'errorCode' => $job->getErrorCode(),
            ],
            'criteria' => array_map(static function (Criterion $criterion) use ($scored): array {
                $data = $criterion->getData();

                return [
                    'id' => $criterion->getId()->toRfc4122(),
                    'name' => $data->name,
                    'kind' => $data->kind->value,
                    'checkType' => $data->checkType?->value,
                    'scored' => $scored[$criterion->getId()->toRfc4122()] ?? 0,
                ];
            }, $evaluation->getRubric()->getCriteria()),
        ];
    }

    /**
     * The evaluation as lists show it, with its runs: letter and label, in blind mode too, since no answer is tied to
     * them here.
     *
     * @param array<string, array{progress: array{scored: int, total: int}, answers: array{scored: int, total: int}}> $progress by evaluation id
     * @param array<string, list<EvaluationRun>>                                                                      $runs     by evaluation id
     *
     * @return array<string, mixed>
     */
    private static function evaluationJson(Evaluation $evaluation, array $progress, array $runs): array
    {
        $id = $evaluation->getId()->toRfc4122();
        $version = $evaluation->getDatasetVersion();
        $utc = static fn (?\DateTimeImmutable $time): ?string => $time?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);

        return [
            'id' => $id,
            'name' => $evaluation->getName(),
            'status' => $evaluation->getStatus()->value,
            'blind' => $evaluation->isBlind(),
            'datasetVersion' => [
                'id' => $version->getId()->toRfc4122(),
                'number' => $version->getNumber(),
                'dataset' => ['id' => $version->getDataset()->getId()->toRfc4122(), 'name' => $version->getDataset()->getName()],
            ],
            'rubric' => ['id' => $evaluation->getRubric()->getId()->toRfc4122(), 'name' => $evaluation->getRubric()->getName()],
            'progress' => $progress[$id]['progress'] ?? ['scored' => 0, 'total' => 0],
            'answers' => $progress[$id]['answers'] ?? ['scored' => 0, 'total' => 0],
            'createdAt' => $utc($evaluation->getCreatedAt()),
            'closedAt' => $utc($evaluation->getClosedAt()),
            'runs' => array_map(static fn (EvaluationRun $entry): array => [
                'letter' => $entry->getLetter(),
                'id' => $entry->getRun()->getId()->toRfc4122(),
                'label' => $entry->getRun()->getLabel(),
            ], $runs[$id] ?? []),
        ];
    }
}
