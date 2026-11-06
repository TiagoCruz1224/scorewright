<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationRun;
use Doctrine\DBAL\Connection;

/**
 * One case of an evaluation, for the Case page (architecture §5): its input, reference and tags, and every run's
 * answer with its scores and comment. With a pair of runs, where the case stands among their largest disagreements.
 * It returns answers, so it is a result route: an evaluator reads it only when the results are not hidden.
 */
final class CaseView
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Results $results,
    ) {
    }

    /**
     * @param array{EvaluationRun, EvaluationRun}|null $pair
     *
     * @return array<string, mixed>|null null when the case is not one of the evaluation's dataset version
     */
    public function of(Evaluation $evaluation, string $caseId, ?array $pair): ?array
    {
        $case = $this->connection->fetchAssociative(
            'SELECT id, case_key, position, input, reference, tags::text AS tags FROM test_case WHERE id = ? AND dataset_version_id = ?',
            [$caseId, $evaluation->getDatasetVersion()->getId()->toRfc4122()],
        );
        if (false === $case) {
            return null;
        }

        $criteria = $evaluation->getRubric()->getCriteria();
        $answers = [];
        $scores = []; // [letter][criterion id] = score
        foreach ($this->connection->fetchAllAssociative(
            'SELECT r.letter, run.id AS run_id, run.label, a.output, i.comment, i.comment_updated_at, u.display_name,
                s.criterion_id, s.value, s.outcome, s.error_code
            FROM evaluation_item i
            JOIN answer a ON a.id = i.answer_id
            JOIN run ON run.id = a.run_id
            JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id
            LEFT JOIN users u ON u.id = i.comment_by_id
            LEFT JOIN score s ON s.evaluation_item_id = i.id
            WHERE i.evaluation_id = ? AND a.test_case_id = ?
            ORDER BY r.letter',
            [$evaluation->getId()->toRfc4122(), $caseId],
        ) as $row) {
            $letter = (string) $row['letter'];
            $answers[$letter] ??= [
                'letter' => $letter,
                'runId' => (string) $row['run_id'],
                'label' => (string) $row['label'],
                'output' => (string) $row['output'],
                'comment' => null === $row['comment'] ? null : [
                    'text' => (string) $row['comment'],
                    'by' => null === $row['display_name'] ? null : (string) $row['display_name'],
                    'updatedAt' => null === $row['comment_updated_at'] ? null : (new \DateTimeImmutable((string) $row['comment_updated_at']))->format(\DATE_ATOM),
                ],
            ];
            if (null !== $row['criterion_id']) {
                $scores[$letter][(string) $row['criterion_id']] = [
                    'value' => null === $row['value'] ? null : (int) $row['value'],
                    'outcome' => (string) $row['outcome'],
                    'errorCode' => null === $row['error_code'] ? null : (string) $row['error_code'],
                ];
            }
        }

        return [
            'case' => [
                'id' => (string) $case['id'],
                'key' => (string) $case['case_key'],
                'position' => (int) $case['position'],
                'input' => (string) $case['input'],
                'reference' => null === $case['reference'] ? null : (string) $case['reference'],
                'tags' => json_decode((string) $case['tags'], true, flags: \JSON_THROW_ON_ERROR),
            ],
            'criteria' => array_map(static fn ($criterion): array => [
                'id' => $criterion->getId()->toRfc4122(),
                'name' => $criterion->getData()->name,
                'kind' => $criterion->getData()->kind->value,
                'checkType' => $criterion->getData()->checkType?->value,
            ], $criteria),
            // Every run's answer, A first; a run that did not answer the case has none. A score not given yet is null.
            'answers' => array_values(array_map(static fn (array $answer): array => $answer + [
                'scores' => array_map(static fn ($criterion): array => ['criterionId' => $criterion->getId()->toRfc4122()] + ($scores[$answer['letter']][$criterion->getId()->toRfc4122()] ?? ['value' => null, 'outcome' => null, 'errorCode' => null]), $criteria),
            ], $answers)),
            'disagreement' => null === $pair ? null : $this->disagreement($evaluation, (string) $case['id'], $pair),
        ];
    }

    /**
     * Where the case stands among the pair's largest disagreements, for "Largest disagreement 1 of 5" and the Previous
     * and Next links. The list has a row per case and criterion, so the case page walks through its cases, each once,
     * in the order of their first row; the line describes that row, the case's largest. Null when it is not listed.
     *
     * @param array{EvaluationRun, EvaluationRun} $pair
     *
     * @return array<string, mixed>|null
     */
    private function disagreement(Evaluation $evaluation, string $caseId, array $pair): ?array
    {
        $rows = $this->results->disagreements($evaluation, ...$pair);
        $cases = array_values(array_unique(array_map(static fn (array $row): string => $row['case']['id'], $rows)));
        $index = array_search($caseId, $cases, true);
        if (false === $index) {
            return null;
        }
        $first = array_values(array_filter($rows, static fn (array $row): bool => $row['case']['id'] === $caseId))[0];

        return [
            'position' => $index + 1,
            'count' => \count($cases),
            'criterion' => $first['criterion'],
            'a' => $first['a'],
            'b' => $first['b'],
            'difference' => $first['difference'],
            'previousCaseId' => $cases[$index - 1] ?? null,
            'nextCaseId' => $cases[$index + 1] ?? null,
        ];
    }
}
