<?php

declare(strict_types=1);

namespace App\Datasets\Controller;

use App\Datasets\Dto\CreateRunRequest;
use App\Datasets\Dto\RunListQuery;
use App\Datasets\Entity\Run;
use App\Datasets\Import\DatasetImporter;
use App\Datasets\Import\ImportErrors;
use App\Datasets\Repository\DatasetVersionRepository;
use App\Datasets\Repository\RunRepository;
use App\Http\ApiProblem;
use App\Http\FormFields;
use App\Projects\ProjectAccess;
use App\Projects\Security\ProjectVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Runs (architecture §5). Members read; owners import and delete. Every answer here is metadata only: no route lists
 * a run's answers (§5, "Run data and blind mode"), so nothing links an answer's text to its run. Imports are all or
 * nothing (ADR 0004).
 */
final class RunController
{
    private const PROJECT = ['projectId' => Requirement::UUID];
    private const RUN = ['projectId' => Requirement::UUID, 'runId' => Requirement::UUID];

    public function __construct(
        private readonly RunRepository $runs,
        private readonly DatasetVersionRepository $versions,
        private readonly DatasetImporter $importer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/projects/{projectId}/runs', name: 'run_list', methods: ['GET'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function list(ProjectAccess $projectAccess, #[MapQueryString] RunListQuery $query = new RunListQuery()): JsonResponse
    {
        $versionId = '' !== $query->datasetVersion ? Uuid::fromString($query->datasetVersion) : null;
        $page = $this->runs->findPageInProject($projectAccess->project->getId(), $versionId, $query->page, $query->perPage);

        return new JsonResponse([
            'items' => array_map(self::runJson(...), $page['items']),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'total' => $page['total'],
        ]);
    }

    /** Multipart: "datasetVersion", "label", "modelDescription" (optional) and "file". */
    #[Route('/api/projects/{projectId}/runs', name: 'run_create', methods: ['POST'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function create(ProjectAccess $projectAccess, Request $request): Response
    {
        $file = $this->importer->uploadedFile($request);
        FormFields::rejectUnknown($request, ['datasetVersion', 'label', 'modelDescription']);
        $body = FormFields::validated($this->validator, new CreateRunRequest(
            FormFields::string($request, 'datasetVersion'),
            FormFields::string($request, 'label'),
            FormFields::string($request, 'modelDescription'),
        ));

        // A version of another project, one that does not exist and a malformed id get the same answer.
        $version = Uuid::isValid($body->datasetVersion) ? $this->versions->findInProject($projectAccess->project->getId(), Uuid::fromString($body->datasetVersion)) : null;
        if (null === $version) {
            throw ApiProblem::validation([['field' => 'datasetVersion', 'code' => 'not_found', 'message' => 'This project has no dataset version with this id. Choose one of its versions.']]);
        }
        if ($this->runs->labelIsTaken($version, $body->label)) {
            throw self::labelTaken();
        }
        $answers = $this->importer->readAnswers($file, $version);
        if ($answers instanceof ImportErrors) {
            return $answers->toResponse();
        }

        try {
            $run = $this->entityManager->wrapInTransaction(fn (): Run => $this->importer->saveRun($projectAccess->project, $version, $body->label, $body->modelDescription, $file, $answers));
        } catch (UniqueConstraintViolationException) {
            throw self::labelTaken(); // imported by a concurrent request
        }

        return new JsonResponse(self::runJson($run), Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}/runs/{runId}', name: 'run_show', methods: ['GET'], requirements: self::RUN)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $runId): JsonResponse
    {
        return new JsonResponse(self::runJson($this->findRun($projectAccess, $runId)));
    }

    /** Deletes the run with its answers (database cascade). */
    #[Route('/api/projects/{projectId}/runs/{runId}', name: 'run_delete', methods: ['DELETE'], requirements: self::RUN)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function delete(ProjectAccess $projectAccess, string $runId): Response
    {
        $run = $this->findRun($projectAccess, $runId);
        // Slice 9: when an evaluation includes this run, answer 409 in_use here, listing those evaluations
        // (architecture §4, "Deleting").
        $this->entityManager->remove($run);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function findRun(ProjectAccess $projectAccess, string $runId): Run
    {
        // The same answer as for a project you are not a member of.
        return $this->runs->findInProject($projectAccess->project->getId(), Uuid::fromString($runId)) ?? throw new ApiProblem(404, 'not_found', 'Not Found');
    }

    private static function labelTaken(): ApiProblem
    {
        return ApiProblem::validation([['field' => 'label', 'code' => 'label_taken', 'message' => 'This dataset version already has a run with this label. Choose another label.']]);
    }

    /**
     * Metadata only, never answers. Coverage: answerCount of datasetVersion.caseCount.
     *
     * @return array<string, mixed>
     */
    private static function runJson(Run $run): array
    {
        $version = $run->getDatasetVersion();

        return [
            'id' => $run->getId()->toRfc4122(),
            'label' => $run->getLabel(),
            'modelDescription' => $run->getModelDescription(),
            'datasetVersion' => [
                'id' => $version->getId()->toRfc4122(),
                'number' => $version->getNumber(),
                'caseCount' => $version->getCaseCount(),
                'dataset' => ['id' => $version->getDataset()->getId()->toRfc4122(), 'name' => $version->getDataset()->getName()],
            ],
            'answerCount' => $run->getAnswerCount(),
            'sourceFilename' => $run->getSourceFilename(),
            'sha256' => $run->getSha256(),
            'createdAt' => $run->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }
}
