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
 * - per tag: the same per criterion and run, for the cases with that tag;
 * - the comparison of two runs, on the cases both answered, and the cases where they disagree most.
 *
 * One scan of the evaluation's scores, grouped by the case's tags (a few dozen combinations), criterion, run, outcome
 * and value, gives both the criteria and the tags; one more query counts the coverage, and one the cases per tag.
 */
final class Results
{
    /** The disagreements listed, and walked through on the case page. */
    public const DISAGREEMENTS = 20;

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
        return $this->inParallel(fn (): array => $this->compute($evaluation));
    }

    /**
     * Run B against run A on the cases both answered, so a case one of them skipped never weighs on one side only:
     * per criterion, the overall score and per tag, A, B and B − A in the criterion's units (points on 1–5,
     * percentage points for a rate, points out of 100 for the overall score); then the largest disagreements.
     *
     * @return array<string, mixed>
     */
    public function comparison(Evaluation $evaluation, EvaluationRun $a, EvaluationRun $b): array
    {
        return $this->inParallel(fn (): array => $this->compare($evaluation, $a, $b));
    }

    /**
     * The (case, criterion) pairs where A and B differ most, measured from 0 to 100 like the overall score: a 1–5 score
     * as (value − 1) / 4 × 100, so each point is 25; a pass 100 and a fail 0. Largest first, whatever its sign, then by
     * the case's position and the criterion's. Only pairs scored in both runs (a check error has no score), and only
     * those that differ; at most self::DISAGREEMENTS.
     *
     * @return list<array{case: array{id: string, key: string, position: int, input: string}, criterion: array{id: string, name: string, measure: string}, a: int, b: int, difference: int, normalised: int}>
     */
    public function disagreements(Evaluation $evaluation, EvaluationRun $a, EvaluationRun $b): array
    {
        return $this->inParallel(fn (): array => $this->findDisagreements($evaluation, $a, $b));
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function inParallel(callable $read): mixed
    {
        // ponytail: for these reads only (set_config local to the transaction), PostgreSQL may use parallel workers below
        // its default size thresholds: at the MVP limits the score scan goes from about 150 to 53 ms, and the coverage
        // from 54 to 34 ms. If results ever outgrow this, ADR 0007's plan B: a summary table kept by the job handler.
        return $this->connection->transactional(function () use ($read): mixed {
            $this->connection->executeQuery("SELECT set_config('parallel_setup_cost', '0', true), set_config('parallel_tuple_cost', '0', true), set_config('min_parallel_table_scan_size', '0', true)");

            return $read();
        });
    }

    /**
     * @return array{runs: list<array<string, mixed>>, criteria: list<array<string, mixed>>, tags: list<array<string, mixed>>}
     */
    private function compute(Evaluation $evaluation): array
    {
        $entries = $this->evaluations->runs([$evaluation])[$evaluation->getId()->toRfc4122()] ?? [];
        $letters = array_map(static fn (EvaluationRun $entry): string => $entry->getLetter(), $entries);
        [$byCriterion, $byTag] = $this->scan($evaluation);

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
                    $normalised[$letter][$key] = self::normalise($criterion, self::average($measures));
                }
            }
            $criteria[] = self::describe($criterion) + ['guidance' => $data->guidance, 'runs' => $runs];
        }

        return [
            'runs' => $this->runs($evaluation, $entries, $normalised),
            'criteria' => $criteria,
            'tags' => $this->tags($evaluation, $letters, $byTag),
        ];
    }

    /**
     * One scan of the evaluation's scores: [criterion][letter] = list of [value (null: a check error), count], and
     * [tag][criterion][letter] = [n, sum of values, check errors]. With a pair of runs, only theirs, on the cases both
     * answered.
     *
     * @param array{string, string}|null $pair run ids
     *
     * @return array{array<string, array<string, list<array{int|null, int}>>>, array<string, array<string, array<string, array{int, int, int}>>>}
     */
    private function scan(Evaluation $evaluation, ?array $pair = null): array
    {
        $where = 'i.evaluation_id = ?';
        $parameters = [$evaluation->getId()->toRfc4122()];
        if (null !== $pair) {
            $where .= ' AND a.run_id IN (?, ?) AND EXISTS (SELECT 1 FROM answer o WHERE o.test_case_id = a.test_case_id AND o.run_id IN (?, ?) AND o.run_id <> a.run_id)';
            array_push($parameters, ...$pair, ...$pair);
        }

        $byCriterion = [];
        $byTag = [];
        $tagLists = []; // the decoded tags of each combination
        foreach ($this->connection->iterateAssociative(
            "SELECT c.tags::text AS tags, s.criterion_id, r.letter, s.outcome, s.value, COUNT(*) AS n
            FROM score s
            JOIN evaluation_item i ON i.id = s.evaluation_item_id
            JOIN answer a ON a.id = i.answer_id
            JOIN test_case c ON c.id = a.test_case_id
            JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id
            WHERE $where
            GROUP BY c.tags::text, s.criterion_id, r.letter, s.outcome, s.value",
            $parameters,
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

        return [$byCriterion, $byTag];
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(Evaluation $evaluation, EvaluationRun $a, EvaluationRun $b): array
    {
        $pair = [$a->getRun()->getId()->toRfc4122(), $b->getRun()->getId()->toRfc4122()];
        $sides = ['a' => $a->getLetter(), 'b' => $b->getLetter()];
        [$byCriterion, $byTag] = $this->scan($evaluation, $pair);
        $weights = self::weights($evaluation);

        $criteria = [];
        $normalised = ['a' => [], 'b' => []];
        foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
            $key = $criterion->getId()->toRfc4122();
            $entry = self::describe($criterion);
            $values = [];
            foreach ($sides as $side => $letter) {
                $measures = self::measures($criterion, $byCriterion[$key][$letter] ?? []);
                $entry[$side] = ['n' => $measures['n'], 'value' => $measures['mean'] ?? $measures['passRate'], 'checkErrors' => $measures['checkErrors']];
                $values[$side] = $measures['n'] > 0 ? self::average($measures) : null;
                if (null !== $values[$side]) {
                    $normalised[$side][$key] = self::normalise($criterion, $values[$side]);
                }
            }
            $criteria[] = $entry + ['difference' => self::difference($criterion, $values['a'], $values['b'])];
        }

        $cases = $this->tagCases($evaluation, $pair);
        $tags = [];
        foreach ($cases as $tag => $count) {
            $tagCriteria = [];
            $tagNormalised = ['a' => [], 'b' => []];
            foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
                $key = $criterion->getId()->toRfc4122();
                $entry = ['id' => $key];
                $values = [];
                foreach ($sides as $side => $letter) {
                    [$n, $sum, $errors] = $byTag[$tag][$key][$letter] ?? [0, 0, 0];
                    $values[$side] = $n > 0 ? $sum / $n : null;
                    $entry[$side] = ['n' => $n, 'value' => self::value($criterion, $values[$side]), 'checkErrors' => $errors];
                    if (null !== $values[$side]) {
                        $tagNormalised[$side][$key] = self::normalise($criterion, $values[$side]);
                    }
                }
                $tagCriteria[] = $entry + ['difference' => self::difference($criterion, $values['a'], $values['b'])];
            }
            $tags[] = ['tag' => (string) $tag, 'cases' => $count, 'overall' => self::overallPair($weights, $tagNormalised), 'criteria' => $tagCriteria];
        }

        return [
            'runs' => array_map(static fn (EvaluationRun $entry): array => ['letter' => $entry->getLetter(), 'id' => $entry->getRun()->getId()->toRfc4122(), 'label' => $entry->getRun()->getLabel()], [$a, $b]),
            'cases' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM answer x JOIN answer y ON y.test_case_id = x.test_case_id AND y.run_id = ? WHERE x.run_id = ?',
                [$pair[1], $pair[0]],
            ),
            'overall' => self::overallPair($weights, $normalised),
            'criteria' => $criteria,
            'tags' => $tags,
            'disagreements' => $this->findDisagreements($evaluation, $a, $b),
        ];
    }

    /**
     * @return list<array{case: array{id: string, key: string, position: int, input: string}, criterion: array{id: string, name: string, measure: string}, a: int, b: int, difference: int, normalised: int}>
     */
    private function findDisagreements(Evaluation $evaluation, EvaluationRun $a, EvaluationRun $b): array
    {
        $criteria = [];
        foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
            $criteria[$criterion->getId()->toRfc4122()] = $criterion;
        }
        [$runA, $runB] = [$a->getRun()->getId()->toRfc4122(), $b->getRun()->getId()->toRfc4122()];
        $gap = "(g.b - g.a) * CASE k.kind WHEN 'scale_1_5' THEN 25 ELSE 100 END";
        $rows = $this->connection->fetchAllAssociative(
            // One pass over both runs' scores, each (case, criterion) with A's value and B's (46 ms at the MVP limits,
            // against 68 for joining the scores to themselves). A check error has no value, so it never takes part.
            "SELECT c.id, c.case_key, c.position, LEFT(c.input, 200) AS input, g.criterion_id, g.a, g.b, $gap AS gap
            FROM (
                SELECT a.test_case_id, s.criterion_id, MIN(s.value) FILTER (WHERE a.run_id = ?) AS a, MIN(s.value) FILTER (WHERE a.run_id = ?) AS b
                FROM score s
                JOIN evaluation_item i ON i.id = s.evaluation_item_id
                JOIN answer a ON a.id = i.answer_id
                WHERE i.evaluation_id = ? AND a.run_id IN (?, ?) AND s.value IS NOT NULL
                GROUP BY a.test_case_id, s.criterion_id
            ) g
            JOIN criterion k ON k.id = g.criterion_id
            JOIN test_case c ON c.id = g.test_case_id
            WHERE g.a <> g.b
            ORDER BY ABS($gap) DESC, c.position, k.position
            LIMIT ".self::DISAGREEMENTS,
            [$runA, $runB, $evaluation->getId()->toRfc4122(), $runA, $runB],
        );

        return array_map(static function (array $row) use ($criteria): array {
            $criterion = $criteria[(string) $row['criterion_id']];
            $scale = CriterionKind::Scale1To5 === $criterion->getData()->kind;
            [$valueA, $valueB] = [(int) $row['a'], (int) $row['b']];

            return [
                'case' => ['id' => (string) $row['id'], 'key' => (string) $row['case_key'], 'position' => (int) $row['position'], 'input' => (string) $row['input']],
                'criterion' => ['id' => $criterion->getId()->toRfc4122(), 'name' => $criterion->getData()->name, 'measure' => $scale ? 'mean' : 'passRate'],
                'a' => $valueA,
                'b' => $valueB,
                // In the criterion's units: points on 1–5, percentage points for a pass or a fail.
                'difference' => ($valueB - $valueA) * ($scale ? 1 : 100),
                'normalised' => (int) $row['gap'],
            ];
        }, $rows);
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
     * The average value from the counts, not the rounded mean or rate: what is worked out from it is rounded once.
     *
     * @param array{n: int, distribution: list<array{value: int, count: int}>} $measures with n > 0
     */
    private static function average(array $measures): float
    {
        return array_sum(array_map(static fn (array $entry): int => $entry['value'] * $entry['count'], $measures['distribution'])) / $measures['n'];
    }

    /** From 0 to 100 (§12.3): a 1–5 mean as (mean − 1) / 4 × 100; the share of passes as a percentage. */
    private static function normalise(Criterion $criterion, float $average): float
    {
        return CriterionKind::Scale1To5 === $criterion->getData()->kind ? ($average - 1) / 4 * 100 : $average * 100;
    }

    /** The mean (2 decimals) or the pass rate (%, 1 decimal) of an average value. */
    private static function value(Criterion $criterion, ?float $average): ?float
    {
        if (null === $average) {
            return null;
        }

        return CriterionKind::Scale1To5 === $criterion->getData()->kind ? round($average, 2) : round($average * 100, 1);
    }

    /** B − A in the criterion's units, from the averages: points on 1–5 (2 decimals), percentage points (1 decimal). */
    private static function difference(Criterion $criterion, ?float $a, ?float $b): ?float
    {
        if (null === $a || null === $b) {
            return null;
        }

        return CriterionKind::Scale1To5 === $criterion->getData()->kind ? round($b - $a, 2) : round(($b - $a) * 100, 1);
    }

    /**
     * @return array{id: string, name: string, kind: string, checkType: string|null, weight: float|null, measure: string}
     */
    private static function describe(Criterion $criterion): array
    {
        $data = $criterion->getData();

        return [
            'id' => $criterion->getId()->toRfc4122(),
            'name' => $data->name,
            'kind' => $data->kind->value,
            'checkType' => $data->checkType?->value,
            'weight' => $data->weight,
            'measure' => CriterionKind::Scale1To5 === $data->kind ? 'mean' : 'passRate',
        ];
    }

    /**
     * @return array<string, float> each criterion's weight, an empty one counting 1 (§12.3)
     */
    private static function weights(Evaluation $evaluation): array
    {
        $weights = [];
        foreach ($evaluation->getRubric()->getCriteria() as $criterion) {
            $weights[$criterion->getId()->toRfc4122()] = $criterion->getData()->weight ?? 1.0;
        }

        return $weights;
    }

    /**
     * The criteria's scores from 0 to 100, averaged with their weights; a criterion without scores is left out, and
     * without any there is no overall score (§12.3). Unrounded.
     *
     * @param array<string, float> $weights
     * @param array<string, float> $normalised [criterion id] = score from 0 to 100
     */
    private static function overall(array $weights, array $normalised): ?float
    {
        $weight = array_sum(array_intersect_key($weights, $normalised));
        $total = array_sum(array_map(static fn (string $criterion, float $score): float => $weights[$criterion] * $score, array_keys($normalised), $normalised));

        return $weight > 0 ? $total / $weight : null;
    }

    /**
     * @param array<string, float>                                    $weights
     * @param array{a: array<string, float>, b: array<string, float>} $normalised
     *
     * @return array{a: float|null, b: float|null, difference: float|null}
     */
    private static function overallPair(array $weights, array $normalised): array
    {
        [$a, $b] = [self::overall($weights, $normalised['a']), self::overall($weights, $normalised['b'])];

        return [
            'a' => null === $a ? null : round($a, 1),
            'b' => null === $b ? null : round($b, 1),
            'difference' => null === $a || null === $b ? null : round($b - $a, 1),
        ];
    }

    /**
     * Each run's coverage and overall score. A criterion without scores for the run is left out; a run without any has
     * no overall score.
     *
     * @param list<EvaluationRun>                 $entries
     * @param array<string, array<string, float>> $normalised
     *
     * @return list<array<string, mixed>>
     */
    private function runs(Evaluation $evaluation, array $entries, array $normalised): array
    {
        $weights = self::weights($evaluation);
        $manual = \count(array_filter($evaluation->getRubric()->getCriteria(), static fn (Criterion $criterion): bool => CriterionKind::Automatic !== $criterion->getData()->kind));
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
            $overall = self::overall($weights, $normalised[$letter] ?? []);
            $runs[] = [
                'letter' => $letter,
                'id' => $entry->getRun()->getId()->toRfc4122(),
                'label' => $entry->getRun()->getLabel(),
                'cases' => $evaluation->getDatasetVersion()->getCaseCount(),
                'answers' => $answers,
                'scored' => $scored,
                'unscored' => $answers - $scored,
                'overall' => null === $overall ? null : round($overall, 1),
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
        $tags = [];
        foreach ($this->tagCases($evaluation) as $tag => $count) {
            $tags[] = [
                'tag' => (string) $tag,
                'cases' => $count,
                'criteria' => array_map(static function (Criterion $criterion) use ($byTag, $tag, $letters): array {
                    $scale = CriterionKind::Scale1To5 === $criterion->getData()->kind;
                    $key = $criterion->getId()->toRfc4122();

                    return [
                        'id' => $key,
                        'runs' => array_map(static function (string $letter) use ($byTag, $tag, $key, $scale, $criterion): array {
                            [$n, $sum, $errors] = $byTag[$tag][$key][$letter] ?? [0, 0, 0];
                            $value = self::value($criterion, $n > 0 ? $sum / $n : null);

                            return [
                                'letter' => $letter,
                                'n' => $n,
                                'mean' => $scale ? $value : null,
                                'passRate' => $scale ? null : $value,
                                'checkErrors' => $errors,
                            ];
                        }, $letters),
                    ];
                }, $evaluation->getRubric()->getCriteria()),
            ];
        }

        return $tags;
    }

    /**
     * The number of cases with each tag, most first, then by name: the version's, or with a pair of runs, those both
     * answered.
     *
     * @param array{string, string}|null $pair run ids
     *
     * @return array<string, int>
     */
    private function tagCases(Evaluation $evaluation, ?array $pair = null): array
    {
        $both = null === $pair ? '' : ' AND EXISTS (SELECT 1 FROM answer x WHERE x.test_case_id = c.id AND x.run_id = ?) AND EXISTS (SELECT 1 FROM answer y WHERE y.test_case_id = c.id AND y.run_id = ?)';
        $counts = [];
        foreach ($this->connection->fetchAllKeyValue(
            "SELECT t.tag, COUNT(*) FROM test_case c CROSS JOIN LATERAL jsonb_array_elements_text(c.tags) AS t(tag) WHERE c.dataset_version_id = ?$both GROUP BY t.tag ORDER BY COUNT(*) DESC, t.tag COLLATE \"C\"",
            [$evaluation->getDatasetVersion()->getId()->toRfc4122(), ...($pair ?? [])],
        ) as $tag => $count) {
            $counts[(string) $tag] = (int) $count;
        }

        return $counts;
    }
}
