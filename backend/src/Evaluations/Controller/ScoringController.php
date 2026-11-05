<?php

declare(strict_types=1);

namespace App\Evaluations\Controller;

use App\Evaluations\Dto\CommentRequest;
use App\Evaluations\Dto\ScoreRequest;
use App\Evaluations\Entity\Criterion;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationItem;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Entity\Score;
use App\Evaluations\Repository\EvaluationItemRepository;
use App\Evaluations\Repository\EvaluationRepository;
use App\Evaluations\Repository\ScoreRepository;
use App\Evaluations\Security\EvaluationVoter;
use App\Http\ApiProblem;
use App\Projects\ProjectAccess;
use App\Users\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Scoring (architecture §5, ADR 0005): owners and evaluators, on open evaluations only. Every route answers with the
 * item: its case, the answer, the criteria, the scores, the comment and the progress. In blind mode the item never says
 * which run the answer is from: no run id, label or letter, and no answer id.
 */
final class ScoringController
{
    private const EVALUATION = ['projectId' => Requirement::UUID, 'evaluationId' => Requirement::UUID];
    private const ITEM = ['projectId' => Requirement::UUID, 'evaluationId' => Requirement::UUID, 'itemId' => Requirement::UUID];
    private const SCORE = ['projectId' => Requirement::UUID, 'evaluationId' => Requirement::UUID, 'itemId' => Requirement::UUID, 'criterionId' => Requirement::UUID];

    public function __construct(
        private readonly EvaluationRepository $evaluations,
        private readonly EvaluationItemRepository $items,
        private readonly ScoreRepository $scores,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** The first item that lacks a manual score; 204 when none does, or when the rubric has no manual criteria. */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/items/next', name: 'evaluation_item_next', methods: ['GET'], requirements: self::EVALUATION)]
    #[IsGranted(EvaluationVoter::SCORE, 'projectAccess')]
    public function next(ProjectAccess $projectAccess, string $evaluationId): Response
    {
        $evaluation = $this->openEvaluation($projectAccess, $evaluationId);
        $manual = \count(self::manualCriteria($evaluation));
        $item = 0 === $manual ? null : $this->items->nextToScore($evaluation, $manual);

        return null === $item ? new Response(null, Response::HTTP_NO_CONTENT) : new JsonResponse($this->itemJson($item));
    }

    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/items/{itemId}', name: 'evaluation_item_show', methods: ['GET'], requirements: self::ITEM)]
    #[IsGranted(EvaluationVoter::SCORE, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $evaluationId, string $itemId): JsonResponse
    {
        return new JsonResponse($this->itemJson($this->findItem($this->openEvaluation($projectAccess, $evaluationId), $itemId)));
    }

    /** {"value"}: 1 to 5 on a scale_1_5 criterion, 1 (pass) or 0 (fail) on a pass_fail one. An upsert. */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/items/{itemId}/scores/{criterionId}', name: 'evaluation_score', methods: ['PUT'], requirements: self::SCORE)]
    #[IsGranted(EvaluationVoter::SCORE, 'projectAccess')]
    public function score(ProjectAccess $projectAccess, string $evaluationId, string $itemId, string $criterionId, #[MapRequestPayload(acceptFormat: 'json')] ScoreRequest $body): JsonResponse
    {
        $evaluation = $this->openEvaluation($projectAccess, $evaluationId);
        $item = $this->findItem($evaluation, $itemId);
        $criterion = self::findCriterion($evaluation, $criterionId);
        $kind = $criterion->getData()->kind;
        if (CriterionKind::Automatic === $kind) {
            throw ApiProblem::validation([['field' => 'criterionId', 'code' => 'not_manual', 'message' => 'This criterion is an automatic check: its result comes from the check, not from a person.']]);
        }
        [$min, $max, $message] = CriterionKind::Scale1To5 === $kind ? [1, 5, 'Use a whole number from 1 to 5.'] : [0, 1, 'Use 1 for pass or 0 for fail.'];
        if ($body->value < $min || $body->value > $max) {
            throw ApiProblem::validation([['field' => 'value', 'code' => 'not_in_range', 'message' => $message]]);
        }

        $this->scores->saveManual($item, $criterion, (int) $body->value, $projectAccess->user);

        return new JsonResponse($this->itemJson($item));
    }

    /** {"comment"}: one per answer, independent of the scores; an empty one clears it. */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/items/{itemId}/comment', name: 'evaluation_comment', methods: ['PUT'], requirements: self::ITEM)]
    #[IsGranted(EvaluationVoter::SCORE, 'projectAccess')]
    public function comment(ProjectAccess $projectAccess, string $evaluationId, string $itemId, #[MapRequestPayload(acceptFormat: 'json')] CommentRequest $body): JsonResponse
    {
        $item = $this->findItem($this->openEvaluation($projectAccess, $evaluationId), $itemId);
        $item->comment((string) $body->comment, $projectAccess->user);
        $this->entityManager->flush();

        return new JsonResponse($this->itemJson($item));
    }

    /** 404 like a project you are not a member of; 409 evaluation_closed once it is closed, for reads too. */
    private function openEvaluation(ProjectAccess $projectAccess, string $evaluationId): Evaluation
    {
        $evaluation = $this->evaluations->findInProject($projectAccess->project->getId(), Uuid::fromString($evaluationId))
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');
        if ($evaluation->isClosed()) {
            throw EvaluationController::closed();
        }

        return $evaluation;
    }

    private function findItem(Evaluation $evaluation, string $itemId): EvaluationItem
    {
        return $this->items->findInEvaluation($evaluation, Uuid::fromString($itemId)) ?? throw new ApiProblem(404, 'not_found', 'Not Found');
    }

    /** A criterion of another rubric is a 404, like an unknown one. */
    private static function findCriterion(Evaluation $evaluation, string $criterionId): Criterion
    {
        foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
            if ($criterion->getId()->toRfc4122() === $criterionId) {
                return $criterion;
            }
        }

        throw new ApiProblem(404, 'not_found', 'Not Found');
    }

    /**
     * @return list<Criterion>
     */
    private static function manualCriteria(Evaluation $evaluation): array
    {
        return array_values(array_filter($evaluation->getRubric()->getCriteria(), static fn (Criterion $criterion): bool => CriterionKind::Automatic !== $criterion->getData()->kind));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemJson(EvaluationItem $item): array
    {
        $evaluation = $item->getEvaluation();
        $answer = $item->getAnswer();
        $case = $answer->getTestCase();
        $person = static fn (?User $user): ?array => null === $user ? null : ['id' => $user->getId()->toRfc4122(), 'displayName' => $user->getDisplayName()];
        $utc = static fn (\DateTimeImmutable $time): string => $time->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);

        $json = [
            'id' => $item->getId()->toRfc4122(),
            'case' => ['key' => $case->getCaseKey(), 'input' => $case->getInput(), 'reference' => $case->getReference()],
            'answer' => $answer->getOutput(),
        ];
        if (!$evaluation->isBlind()) {
            $entry = $this->entityManager->getRepository(EvaluationRun::class)->findOneBy(['evaluation' => $evaluation, 'run' => $answer->getRun()]);
            $json['run'] = ['letter' => $entry?->getLetter(), 'label' => $answer->getRun()->getLabel()];
        }

        return $json + [
            'criteria' => array_map(static function (Criterion $criterion): array {
                $data = $criterion->getData();

                return ['id' => $criterion->getId()->toRfc4122(), 'kind' => $data->kind->value, 'name' => $data->name, 'guidance' => $data->guidance, 'weight' => $data->weight];
            }, $evaluation->getRubric()->getCriteria()),
            'scores' => array_map(static fn (Score $score): array => [
                'criterionId' => $score->getCriterion()->getId()->toRfc4122(),
                'value' => $score->getValue(),
                'outcome' => $score->getOutcome(),
                'errorCode' => $score->getErrorCode(),
                'source' => $score->getSource(),
                'scoredBy' => $person($score->getScoredBy()),
                'updatedAt' => $utc($score->getUpdatedAt()),
            ], $this->scores->forItem($item)),
            'comment' => null === $item->getComment() ? null : [
                'text' => $item->getComment(),
                'by' => $person($item->getCommentBy()),
                'updatedAt' => null === $item->getCommentUpdatedAt() ? null : $utc($item->getCommentUpdatedAt()),
            ],
            'progress' => $this->evaluations->progress([$evaluation])[$evaluation->getId()->toRfc4122()] ?? ['scored' => 0, 'total' => 0],
        ];
    }
}
