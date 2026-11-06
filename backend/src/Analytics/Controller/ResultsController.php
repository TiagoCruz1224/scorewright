<?php

declare(strict_types=1);

namespace App\Analytics\Controller;

use App\Analytics\CaseView;
use App\Analytics\CsvExport;
use App\Analytics\Results;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Repository\EvaluationRepository;
use App\Evaluations\Security\EvaluationAccess;
use App\Evaluations\Security\EvaluationVoter;
use App\Http\ApiProblem;
use App\Projects\ProjectAccess;
use App\Projects\Security\ProjectVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * An evaluation's results, its comparison of two runs, one case and the CSV export (architecture §5, §7). Members read
 * them, except an evaluator while a blind evaluation is open (EvaluationVoter::READ_RESULTS): 403
 * results_hidden_until_closed, so nobody scoring it learns which run does better, or reads an answer with its run.
 */
final class ResultsController
{
    private const REQUIREMENTS = ['projectId' => Requirement::UUID, 'evaluationId' => Requirement::UUID];

    public function __construct(
        private readonly EvaluationRepository $evaluations,
        private readonly Results $results,
        private readonly CaseView $cases,
        private readonly CsvExport $export,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/results', name: 'evaluation_results', methods: ['GET'], requirements: self::REQUIREMENTS)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $evaluationId): JsonResponse
    {
        return self::json($this->results->of($this->readable($projectAccess, $evaluationId)));
    }

    /** ?runA=&runB=: two different runs of the evaluation, by id; B − A everywhere. */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/comparison', name: 'evaluation_comparison', methods: ['GET'], requirements: self::REQUIREMENTS)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function comparison(ProjectAccess $projectAccess, string $evaluationId, Request $request): JsonResponse
    {
        $evaluation = $this->readable($projectAccess, $evaluationId);
        [$a, $b] = $this->pair($evaluation, $request, required: true) ?? throw new \LogicException('A required pair is never null');

        return self::json($this->results->comparison($evaluation, $a, $b));
    }

    /** A case of the evaluation's dataset version; with ?runA=&runB=, its place among their largest disagreements. */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/cases/{caseId}', name: 'evaluation_case', methods: ['GET'], requirements: self::REQUIREMENTS + ['caseId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function case(ProjectAccess $projectAccess, string $evaluationId, string $caseId, Request $request): JsonResponse
    {
        $evaluation = $this->readable($projectAccess, $evaluationId);
        $pair = $this->pair($evaluation, $request, required: false);

        return self::json($this->cases->of($evaluation, Uuid::fromString($caseId)->toRfc4122(), $pair) ?? throw new ApiProblem(404, 'not_found', 'Not Found'));
    }

    /**
     * The results as CSV, streamed (CsvExport). An attachment named after the evaluation: its letters and digits, the
     * rest as dashes, with an ASCII name for clients that do not read the UTF-8 one.
     */
    #[Route('/api/projects/{projectId}/evaluations/{evaluationId}/export.csv', name: 'evaluation_export', methods: ['GET'], requirements: self::REQUIREMENTS)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function export(ProjectAccess $projectAccess, string $evaluationId): StreamedResponse
    {
        $evaluation = $this->readable($projectAccess, $evaluationId);
        $response = new StreamedResponse(function () use ($evaluation): void {
            $output = fopen('php://output', 'w') ?: throw new \RuntimeException('Cannot write the export');
            $this->export->write($evaluation, $output);
            fclose($output);
        }, 200, ['Content-Type' => 'text/csv; charset=utf-8']);
        $name = static fn (string $pattern): string => mb_strtolower(trim((string) preg_replace($pattern, '-', $evaluation->getName()), '-'));
        $utf8 = mb_substr($name('/[^\p{L}\p{N}]+/u'), 0, 80) ?: 'evaluation';
        $ascii = substr($name('/[^A-Za-z0-9]+/'), 0, 80) ?: 'evaluation';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, "$utf8-results.csv", "$ascii-results.csv"));

        return $response;
    }

    private function readable(ProjectAccess $projectAccess, string $evaluationId): Evaluation
    {
        // The same answer as for a project you are not a member of.
        $evaluation = $this->evaluations->findInProject($projectAccess->project->getId(), Uuid::fromString($evaluationId))
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');
        if (!$this->security->isGranted(EvaluationVoter::READ_RESULTS, new EvaluationAccess($projectAccess, $evaluation))) {
            throw new ApiProblem(403, 'results_hidden_until_closed', 'The results of a blind evaluation stay hidden from evaluators until the owner closes it');
        }

        return $evaluation;
    }

    /**
     * The runs of ?runA= and ?runB=: two different runs of the evaluation, or 422 with the field. When the pair is not
     * required, neither may be given (null), but not one alone.
     *
     * @return array{EvaluationRun, EvaluationRun}|null
     */
    private function pair(Evaluation $evaluation, Request $request, bool $required): ?array
    {
        $ids = ['runA' => $request->query->getString('runA'), 'runB' => $request->query->getString('runB')];
        if (!$required && '' === $ids['runA'] && '' === $ids['runB']) {
            return null;
        }
        $runs = [];
        foreach ($this->evaluations->runs([$evaluation])[$evaluation->getId()->toRfc4122()] ?? [] as $entry) {
            $runs[$entry->getRun()->getId()->toRfc4122()] = $entry;
        }

        $errors = [];
        $chosen = [];
        foreach ($ids as $field => $id) {
            // A run of another evaluation, one that does not exist and a malformed id get the same answer.
            $entry = Uuid::isValid($id) ? $runs[Uuid::fromString($id)->toRfc4122()] ?? null : null;
            if (null !== $entry) {
                $chosen[$field] = $entry;
            } elseif ('' === $id) {
                $errors[] = ['field' => $field, 'code' => 'required', 'message' => 'Choose one of the evaluation’s runs.'];
            } else {
                $errors[] = ['field' => $field, 'code' => 'not_found', 'message' => 'This evaluation has no run with this id. Choose one of its runs.'];
            }
        }
        [$a, $b] = [$chosen['runA'] ?? null, $chosen['runB'] ?? null];
        if (null !== $a && $a === $b) {
            $errors[] = ['field' => 'runB', 'code' => 'same_run', 'message' => 'Choose two different runs: a run compared with itself has no differences.'];
        }
        if ([] !== $errors || null === $a || null === $b) {
            throw ApiProblem::validation($errors);
        }

        return [$a, $b];
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): JsonResponse
    {
        // Means, rates and scores stay numbers with a fraction: 4.0, not 4.
        return (new JsonResponse())->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION)->setData($data);
    }
}
