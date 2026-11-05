<?php

declare(strict_types=1);

namespace App\Analytics\Controller;

use App\Analytics\Results;
use App\Evaluations\Repository\EvaluationRepository;
use App\Evaluations\Security\EvaluationAccess;
use App\Evaluations\Security\EvaluationVoter;
use App\Http\ApiProblem;
use App\Projects\ProjectAccess;
use App\Projects\Security\ProjectVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/** An evaluation's results (architecture §5, §7): numbers only, never an answer. */
final class ResultsController
{
    public function __construct(
        private readonly EvaluationRepository $evaluations,
        private readonly Results $results,
        private readonly Security $security,
    ) {
    }

    /**
     * Members read them, except an evaluator while a blind evaluation is open (EvaluationVoter::READ_RESULTS): 403
     * results_hidden_until_closed, so nobody scoring it learns which run does better.
     */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/results', name: 'evaluation_results', methods: ['GET'], requirements: ['projectId' => Requirement::UUID, 'evaluationId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $evaluationId): JsonResponse
    {
        // The same answer as for a project you are not a member of.
        $evaluation = $this->evaluations->findInProject($projectAccess->project->getId(), Uuid::fromString($evaluationId))
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');
        if (!$this->security->isGranted(EvaluationVoter::READ_RESULTS, new EvaluationAccess($projectAccess, $evaluation))) {
            throw new ApiProblem(403, 'results_hidden_until_closed', 'The results of a blind evaluation stay hidden from evaluators until the owner closes it');
        }

        // Means, rates and scores stay numbers with a fraction: 4.0, not 4.
        return (new JsonResponse())->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION)->setData($this->results->of($evaluation));
    }
}
