<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Evaluations\Entity\Criterion;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Repository\EvaluationRepository;
use Doctrine\DBAL\Connection;

/**
 * An evaluation's results, computed on read with SQL aggregates (ADR 0007, architecture §7): no answers, only numbers.
 * - per criterion and run: n, the mean (scale 1–5) or the pass rate in % (pass/fail and automatic), the distribution,
 *   and the check errors, which are counted apart and never as a fail;
 * - per run: its coverage (answers in the evaluation, those with every manual score) and its overall score (§12.3);
 * - per tag: the same per criterion and run, for the cases with that tag.
 *
 * One scan of the evaluation's scores, grouped by the case's tags (a few dozen combinations), criterion, run, outcome
 * and value, gives both the criteria and the tags; one more query counts the coverage, and one the cases per tag.
 */
final class Results
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EvaluationRepository $evaluations,
    ) {
    }

    /**
     * @return array{runs: list<array<string, mixed>>, criteria: list<array<string, mixed>>, tags: list<array<string, mixed>>}
     */
    public function of(Evaluation $evaluation): array
    {
        // ponytail: for these reads only (set_config local to the transaction), PostgreSQL may use parallel workers below
        // its default size thresholds: at the MVP limits the score scan goes from about 150 to 53 ms, and the coverage
        // from 54 to 34 ms. If results ever outgrow this, ADR 0007's plan B: a summary table kept by the job handler.
        return $this->connection->transactional(function () use ($evaluation): array {
            $this->connection->executeQuery("SELECT set_config('parallel_setup_cost', '0', true), set_config('parallel_tuple_cost', '0', true), set_config('min_parallel_table_scan_size', '0', true)");

            return $this->compute($evaluation);
        });
    }

    /**
     * @return array{runs: list<array<string, mixed>>, criteria: list<array<string, mixed>>, tags: list<array<string, mixed>>}
     */
    private function compute(Evaluation $evaluation): array
    {
        $id = $evaluation->getId()->toRfc4122();
        $entries = $this->evaluations->runs([$evaluation])[$id] ?? [];
        $letters = array_map(static fn (EvaluationRun $entry): string => $entry->getLetter(), $entries);

        $byCriterion = []; // [criterion][letter] = list of [outcome, value, count]
        $byTag = []; // [tag][criterion][letter] = [n, sum of values, check errors]
        $tagLists = []; // the decoded tags of each combination
        foreach ($this->connection->iterateAssociative(
            'SELECT c.tags::text AS tags, s.criterion_id, r.letter, s.outcome, s.value, COUNT(*) AS n
            FROM score s
            JOIN evaluation_item i ON i.id = s.evaluation_item_id
            JOIN answer a ON a.id = i.answer_id
            JOIN test_case c ON c.id = a.test_case_id
            JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id
            WHERE i.evaluation_id = ?
            GROUP BY c.tags::text, s.criterion_id, r.letter, s.outcome, s.value',
            [$id],
        ) as $row) {
            [$criterion, $letter, $count] = [(string) $row['criterion_id'], (string) $row['letter'], (int) $row['n']];
            $value = 'check_error' === $row['outcome'] || null === $row['value'] ? null : (int) $row['value'];
            $byCriterion[$criterion][$letter][] = [$value, $count];
            /** @var list<string> $tags */
            $tags = $tagLists[(string) $row['tags']] ??= json_decode((string) $row['tags'], true, flags: \JSON_THROW_ON_ERROR);
            foreach ($tags as $tag) {
                $totals = $byTag[$tag][$criterion][$letter] ?? [0, 0, 0];
                $byTag[$tag][$criterion][$letter] = null === $value ? [$totals[0], $totals[1], $totals[2] + $count] : [$totals[0] + $count, $totals[1] + $value * $count, $totals[2]];
            }
        }

        $criteria = [];
        $normalised = []; // [letter][criterion id] = mean score from 0 to 100 (§12.3)
        foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
            $data = $criterion->getData();
            $key = $criterion->getId()->toRfc4122();
            $runs = [];
            foreach ($letters as $letter) {
                $measures = self::measures($criterion, $byCriterion[$key][$letter] ?? []);
                $runs[] = ['letter' => $letter] + $measures;
                if ($measures['n'] > 0) {
                    // From the counts, not the rounded mean or rate: the overall score is rounded once, at the end.
                    $average = array_sum(array_map(static fn (array $entry): int => $entry['value'] * $entry['count'], $measures['distribution'])) / $measures['n'];
                    $normalised[$letter][$key] = CriterionKind::Scale1To5 === $data->kind ? ($average - 1) / 4 * 100 : $average * 100;
                }
            }
            $criteria[] = [
                'id' => $key,
                'name' => $data->name,
                'kind' => $data->kind->value,
                'checkType' => $data->checkType?->value,
                'weight' => $data->weight,
                'guidance' => $data->guidance,
                'measure' => CriterionKind::Scale1To5 === $data->kind ? 'mean' : 'passRate',
                'runs' => $runs,
            ];
        }

        return [
            'runs' => $this->runs($evaluation, $entries, $normalised),
            'criteria' => $criteria,
            'tags' => $this->tags($evaluation, $letters, $byTag),
        ];
    }

    /**
     * @param list<array{int|null, int}> $groups value (null: a check error) and count
     *
     * @return array{n: int, mean: float|null, passRate: float|null, distribution: list<array{value: int, count: int}>, checkErrors: int}
     */
    private static function measures(Criterion $criterion, array $groups): array
    {
        $scale = CriterionKind::Scale1To5 === $criterion->getData()->kind;
        $counts = array_fill_keys($scale ? [1, 2, 3, 4, 5] : [1, 0], 0);
        $errors = 0;
        foreach ($groups as [$value, $count]) {
            if (null === $value) {
                $errors += $count;
            } else {
                $counts[$value] = ($counts[$value] ?? 0) + $count;
            }
        }
        $n = array_sum($counts);
        $sum = array_sum(array_map(static fn (int $value, int $count): int => $value * $count, array_keys($counts), $counts));

        return [
            'n' => $n,
            'mean' => $scale && $n > 0 ? round($sum / $n, 2) : null,
            'passRate' => !$scale && $n > 0 ? round($sum / $n * 100, 1) : null,
            'distribution' => array_map(static fn (int $value, int $count): array => ['value' => $value, 'count' => $count], array_keys($counts), array_values($counts)),
            'checkErrors' => $errors,
        ];
    }

    /**
     * Each run's coverage and overall score: the criteria's means from 0 to 100 (scale: (mean − 1) / 4 × 100; pass rate
     * as it is), averaged with their weights, an empty weight counting 1 (§12.3). A criterion without scores for the run
     * is left out; a run without any has no overall score.
     *
     * @param list<EvaluationRun>                 $entries
     * @param array<string, array<string, float>> $normalised
     *
     * @return list<array<string, mixed>>
     */
    private function runs(Evaluation $evaluation, array $entries, array $normalised): array
    {
        $weights = [];
        $manual = 0;
        foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
            $weights[$criterion->getId()->toRfc4122()] = $criterion->getData()->weight ?? 1.0;
            $manual += CriterionKind::Automatic === $criterion->getData()->kind ? 0 : 1;
        }
        // Coverage: the run's answers in the evaluation (a case it did not answer has no item, so it never counts) and
        // those with every manual score, counted on the manual criteria's scores only (through score (criterion_id)).
        $coverage = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT r.letter, COUNT(*) AS answers, COUNT(*) FILTER (WHERE COALESCE(m.n, 0) >= ?) AS scored
            FROM evaluation_item i
            JOIN answer a ON a.id = i.answer_id
            JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id
            LEFT JOIN (
                SELECT s.evaluation_item_id, COUNT(*) AS n FROM score s
                JOIN criterion c ON c.id = s.criterion_id
                WHERE c.rubric_id = ? AND c.kind <> 'automatic'
                GROUP BY s.evaluation_item_id
            ) m ON m.evaluation_item_id = i.id
            WHERE i.evaluation_id = ?
            GROUP BY r.letter",
            [$manual, $evaluation->getRubric()->getId()->toRfc4122(), $evaluation->getId()->toRfc4122()],
        ) as $row) {
            $coverage[(string) $row['letter']] = [(int) $row['answers'], (int) $row['scored']];
        }

        $runs = [];
        foreach ($entries as $entry) {
            $letter = $entry->getLetter();
            [$answers, $scored] = $coverage[$letter] ?? [0, 0];
            $means = $normalised[$letter] ?? [];
            $weight = array_sum(array_intersect_key($weights, $means));
            $total = array_sum(array_map(static fn (string $criterion, float $mean): float => $weights[$criterion] * $mean, array_keys($means), $means));
            $runs[] = [
                'letter' => $letter,
                'id' => $entry->getRun()->getId()->toRfc4122(),
                'label' => $entry->getRun()->getLabel(),
                'cases' => $evaluation->getDatasetVersion()->getCaseCount(),
                'answers' => $answers,
                'scored' => $scored,
                'unscored' => $answers - $scored,
                'overall' => $weight > 0 ? round($total / $weight, 1) : null,
            ];
        }

        return $runs;
    }

    /**
     * Per tag: the version's cases with it, and per criterion and run n, the mean or pass rate, and the check errors.
     * Most cases first.
     *
     * @param list<string>                                                      $letters
     * @param array<string, array<string, array<string, array{int, int, int}>>> $byTag   [tag][criterion][letter] = [n, sum, errors]
     *
     * @return list<array<string, mixed>>
     */
    private function tags(Evaluation $evaluation, array $letters, array $byTag): array
    {
        $cases = $this->connection->fetchAllKeyValue(
            'SELECT t.tag, COUNT(*) FROM test_case c CROSS JOIN LATERAL jsonb_array_elements_text(c.tags) AS t(tag) WHERE c.dataset_version_id = ? GROUP BY t.tag',
            [$evaluation->getDatasetVersion()->getId()->toRfc4122()],
        );

        $tags = [];
        foreach ($cases as $tag => $count) {
            $tags[] = [
                'tag' => (string) $tag,
                'cases' => (int) $count,
                'criteria' => array_map(static function (Criterion $criterion) use ($byTag, $tag, $letters): array {
                    $scale = CriterionKind::Scale1To5 === $criterion->getData()->kind;
                    $key = $criterion->getId()->toRfc4122();

                    return [
                        'id' => $key,
                        'runs' => array_map(static function (string $letter) use ($byTag, $tag, $key, $scale): array {
                            [$n, $sum, $errors] = $byTag[(string) $tag][$key][$letter] ?? [0, 0, 0];

                            return [
                                'letter' => $letter,
                                'n' => $n,
                                'mean' => $scale && $n > 0 ? round($sum / $n, 2) : null,
                                'passRate' => !$scale && $n > 0 ? round($sum / $n * 100, 1) : null,
                                'checkErrors' => $errors,
                            ];
                        }, $letters),
                    ];
                }, $evaluation->getRubric()->getCriteria()),
            ];
        }
        usort($tags, static fn (array $a, array $b): int => [$b['cases'], $a['tag']] <=> [$a['cases'], $b['tag']]);

        return $tags;
    }
}
