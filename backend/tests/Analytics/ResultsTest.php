<?php

declare(strict_types=1);

namespace App\Tests\Analytics;

use App\Datasets\Entity\Answer;
use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Datasets\Entity\TestCase;
use App\Evaluations\Checks\RunAutomaticChecks;
use App\Evaluations\Checks\RunAutomaticChecksHandler;
use App\Evaluations\Entity\CheckJob;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationItem;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Repository\ScoreRepository;
use App\Projects\Entity\Project;
use App\Tests\ApiTestCase;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The results of an evaluation (architecture §7, ADR 0007), on a set small enough to work out by hand:
 * - cases c1 (tags billing, refund), c2 (billing) and c3 (no tag, no reference);
 * - run A answers all three; run B only c1 and c2 (a partial run);
 * - Tone (1–5, weight 2), Correct (pass/fail, no weight: 1) and Exact (exact_match, weight 0.5).
 */
final class ResultsTest extends ApiTestCase
{
    /** [run][case] = [Tone, Correct] */
    private const MANUAL = ['A' => ['c1' => [5, 1], 'c2' => [3, 0], 'c3' => [4, 1]], 'B' => ['c1' => [2, 1], 'c2' => [4, 1]]];
    /** [run][case] = the answer; Exact passes when it equals the case's reference "Reference cN" */
    private const OUTPUTS = ['A' => ['c1' => 'reference c1', 'c2' => 'Something else', 'c3' => 'Anything'], 'B' => ['c1' => 'Nope', 'c2' => 'Reference c2']];

    public function testTheResultsAreRightToTheNumber(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $evaluation = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false);
        $client->loginUser($fixture['owner']);

        $response = self::requestJson($client, 'GET', "/api/projects/{$fixture['project']->getId()}/evaluations/{$evaluation->getId()}/results");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $results = self::json($response);
        self::assertSame(['runs', 'criteria', 'tags'], array_keys($results));

        // Coverage: B did not answer c3, so c3 is not B's to score. Overall (§12.3), with the weights 2, 1 and 0.5:
        // A = (2 × 75 + 1 × 66.67 + 0.5 × 50) / 3.5 = 69.0; B = (2 × 50 + 1 × 100 + 0.5 × 50) / 3.5 = 64.3.
        self::assertSame([
            ['A', 3, 3, 3, 0, 69.0],
            ['B', 3, 2, 2, 0, 64.3],
        ], array_map(static fn (array $run): array => [$run['letter'], $run['cases'], $run['answers'], $run['scored'], $run['unscored'], $run['overall']], $results['runs']));

        [$tone, $correct, $exact] = $results['criteria'];
        self::assertSame(['Tone', 'scale_1_5', null, 2.0, 'mean'], [$tone['name'], $tone['kind'], $tone['checkType'], $tone['weight'], $tone['measure']]);
        self::assertSame([
            ['A', 3, 4.0, null, [0, 0, 1, 1, 1], 0],
            ['B', 2, 3.0, null, [0, 1, 0, 1, 0], 0],
        ], self::measures($tone));
        self::assertSame(['passRate', null], [$correct['measure'], $correct['weight']]);
        // Pass/fail: the distribution is 1 (pass) then 0 (fail).
        self::assertSame([
            ['A', 3, null, 66.7, [2, 1], 0],
            ['B', 2, null, 100.0, [2, 0], 0],
        ], self::measures($correct));
        // A check error is counted apart, never as a fail: A's c3 has no reference.
        self::assertSame(['automatic', 'exact_match', 0.5], [$exact['kind'], $exact['checkType'], $exact['weight']]);
        self::assertSame([
            ['A', 2, null, 50.0, [1, 1], 1],
            ['B', 2, null, 50.0, [1, 1], 0],
        ], self::measures($exact));

        // Per tag, most cases first; c3 has no tag.
        self::assertSame([['billing', 2], ['refund', 1]], array_map(static fn (array $tag): array => [$tag['tag'], $tag['cases']], $results['tags']));
        $tagged = static fn (int $tag, int $criterion): array => array_map(
            static fn (array $run): array => [$run['letter'], $run['n'], $run['mean'] ?? $run['passRate'], $run['checkErrors']],
            $results['tags'][$tag]['criteria'][$criterion]['runs'],
        );
        self::assertSame([['A', 2, 4.0, 0], ['B', 2, 3.0, 0]], $tagged(0, 0)); // billing, Tone
        self::assertSame([['A', 2, 50.0, 0], ['B', 2, 100.0, 0]], $tagged(0, 1)); // billing, Correct
        self::assertSame([['A', 1, 5.0, 0], ['B', 1, 2.0, 0]], $tagged(1, 0)); // refund, Tone
        self::assertSame([['A', 1, 100.0, 0], ['B', 1, 0.0, 0]], $tagged(1, 2)); // refund, Exact

        // Numbers only: no answer, no case text.
        foreach ([...array_merge(...array_values(self::OUTPUTS)), 'Input c1'] as $text) {
            self::assertStringNotContainsString($text, (string) $response->getContent());
        }
    }

    /**
     * B against A on the cases both answered, c1 and c2: c3, which only A answered, weighs on neither side. So A's Tone
     * is (5 + 3) / 2 = 4.0 here, and its overall score (2 × 75 + 1 × 50 + 0.5 × 50) / 3.5 = 64.3, not the 69.0 of the
     * results, where c3 counts too.
     */
    public function testTheComparisonIsRightToTheNumber(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $evaluation = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false);
        $runs = self::runIds($evaluation);
        $client->loginUser($fixture['owner']);

        $response = self::requestJson($client, 'GET', self::path($evaluation, "/comparison?runA={$runs['A']}&runB={$runs['B']}"));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $comparison = self::json($response);
        self::assertSame(['runs', 'cases', 'overall', 'criteria', 'tags', 'disagreements'], array_keys($comparison));
        self::assertSame([['A', $runs['A'], 'run-A'], ['B', $runs['B'], 'run-B']], array_map(static fn (array $run): array => [$run['letter'], $run['id'], $run['label']], $comparison['runs']));
        self::assertSame(2, $comparison['cases']);
        self::assertSame(['a' => 64.3, 'b' => 64.3, 'difference' => 0.0], $comparison['overall']);
        // B − A in each criterion's units: points on 1–5, percentage points for a rate.
        self::assertSame([
            ['Tone', 'mean', [2, 4.0, 0], [2, 3.0, 0], -1.0],
            ['Correct', 'passRate', [2, 50.0, 0], [2, 100.0, 0], 50.0],
            ['Exact', 'passRate', [2, 50.0, 0], [2, 50.0, 0], 0.0],
        ], self::sides($comparison['criteria'], 'name'));

        // Per tag, on the same cases. refund is c1 only: A = (2 × 100 + 100 + 0.5 × 100) / 3.5 = 100.0,
        // B = (2 × 25 + 100 + 0) / 3.5 = 42.9.
        self::assertSame([['billing', 2], ['refund', 1]], array_map(static fn (array $tag): array => [$tag['tag'], $tag['cases']], $comparison['tags']));
        [$billing, $refund] = $comparison['tags'];
        self::assertSame(['a' => 64.3, 'b' => 64.3, 'difference' => 0.0], $billing['overall']);
        self::assertSame(['a' => 100.0, 'b' => 42.9, 'difference' => -57.1], $refund['overall']);
        self::assertSame([
            [$comparison['criteria'][0]['id'], [1, 5.0, 0], [1, 2.0, 0], -3.0],
            [$comparison['criteria'][1]['id'], [1, 100.0, 0], [1, 100.0, 0], 0.0],
            [$comparison['criteria'][2]['id'], [1, 100.0, 0], [1, 0.0, 0], -100.0],
        ], self::sides($refund['criteria'], 'id'));

        // The pairs that differ, from 0 to 100: a pass against a fail is 100, each point on 1–5 is 25. Ties go by the
        // case's position, then the criterion's. c1's Correct is the same in both, and c3 has no B.
        self::assertSame([
            ['c1', 'Exact', 1, 0, -100, -100],
            ['c2', 'Correct', 0, 1, 100, 100],
            ['c2', 'Exact', 0, 1, 100, 100],
            ['c1', 'Tone', 5, 2, -3, -75],
            ['c2', 'Tone', 3, 4, 1, 25],
        ], self::disagreements($comparison));
        self::assertSame(['Input c1', 1, 'passRate'], [$comparison['disagreements'][0]['case']['input'], $comparison['disagreements'][0]['case']['position'], $comparison['disagreements'][0]['criterion']['measure']]);
        // Case inputs, but no answer: the answers are on the case page.
        foreach (array_merge(...array_values(self::OUTPUTS)) as $text) {
            self::assertStringNotContainsString($text, (string) $response->getContent());
        }

        // Swapped, every sign turns.
        $swapped = self::json(self::requestJson($client, 'GET', self::path($evaluation, "/comparison?runA={$runs['B']}&runB={$runs['A']}")));
        self::assertSame([1.0, -50.0, 0.0], array_column($swapped['criteria'], 'difference'));
        self::assertSame([100, -100, -100, 75, -25], array_column($swapped['disagreements'], 'normalised'));
    }

    /** A pair needs a value in both runs: a score not given yet, or a check error, keeps it out, and out of the means. */
    public function testOnlyPairsScoredInBothRunsAreCompared(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $evaluation = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false);
        $runs = self::runIds($evaluation);
        [$tone, , $exact] = $evaluation->getRubric()->getCriteria();
        $connection = self::service(Connection::class, 'doctrine.dbal.default_connection');
        $connection->executeStatement('DELETE FROM score WHERE evaluation_item_id = ? AND criterion_id = ?', [self::itemId($evaluation, 'B', 'c2'), $tone->getId()->toRfc4122()]);
        $connection->executeStatement("UPDATE score SET outcome = 'check_error', value = NULL, error_code = 'missing_reference' WHERE evaluation_item_id = ? AND criterion_id = ?", [self::itemId($evaluation, 'A', 'c1'), $exact->getId()->toRfc4122()]);
        $client->loginUser($fixture['owner']);

        $comparison = self::json(self::requestJson($client, 'GET', self::path($evaluation, "/comparison?runA={$runs['A']}&runB={$runs['B']}")));

        self::assertSame([
            ['Tone', 'mean', [2, 4.0, 0], [1, 2.0, 0], -2.0],
            ['Correct', 'passRate', [2, 50.0, 0], [2, 100.0, 0], 50.0],
            ['Exact', 'passRate', [1, 0.0, 1], [2, 50.0, 0], 50.0],
        ], self::sides($comparison['criteria'], 'name'));
        self::assertSame([['c2', 'Correct', 0, 1, 100, 100], ['c2', 'Exact', 0, 1, 100, 100], ['c1', 'Tone', 5, 2, -3, -75]], self::disagreements($comparison));
    }

    public function testTheComparisonNeedsTwoDifferentRunsOfTheEvaluation(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $evaluation = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false);
        $other = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false, name: 'Another');
        $runs = self::runIds($evaluation);
        $client->loginUser($fixture['owner']);
        $errors = static function (string $query) use ($client, $evaluation): array {
            $response = self::requestJson($client, 'GET', self::path($evaluation, "/comparison$query"));
            self::assertProblem($response, 422, 'validation_failed');

            return array_map(static fn (array $error): string => "{$error['field']} {$error['code']}", self::json($response)['errors']);
        };

        self::assertSame(['runA required', 'runB required'], $errors(''));
        self::assertSame(['runB same_run'], $errors("?runA={$runs['A']}&runB={$runs['A']}"));
        self::assertSame(['runA not_found'], $errors('?runA='.self::runIds($other)['A']."&runB={$runs['B']}"));
        self::assertSame(['runA not_found', 'runB required'], $errors('?runA=nope'));
        // The case page takes both or neither.
        $response = self::requestJson($client, 'GET', self::path($evaluation, '/cases/'.self::caseIds($evaluation)['c1']."?runA={$runs['A']}"));
        self::assertProblem($response, 422, 'validation_failed');
        self::assertSame('runB', self::json($response)['errors'][0]['field']);
    }

    public function testTheCasePageShowsEveryRunsAnswerWithItsScoresAndComment(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $evaluation = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false);
        $other = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false, name: 'Another');
        [$runs, $cases] = [self::runIds($evaluation), self::caseIds($evaluation)];
        $client->loginUser($fixture['owner']);
        $commented = self::requestJson($client, 'PUT', self::path($evaluation, '/items/'.self::itemId($evaluation, 'B', 'c1').'/comment'), ['comment' => "Wrong.\nNo refund offered."]);
        self::assertSame(200, $commented->getStatusCode(), (string) $commented->getContent());

        $view = self::json(self::requestJson($client, 'GET', self::path($evaluation, "/cases/{$cases['c1']}")));

        self::assertSame(['case', 'criteria', 'answers', 'disagreement'], array_keys($view));
        self::assertSame(['id' => $cases['c1'], 'key' => 'c1', 'position' => 1, 'input' => 'Input c1', 'reference' => 'Reference c1', 'tags' => ['billing', 'refund']], $view['case']);
        self::assertSame(['Tone', 'Correct', 'Exact'], array_column($view['criteria'], 'name'));
        self::assertSame([
            ['A', $runs['A'], 'run-A', 'reference c1', [5, 1, 1], null],
            ['B', $runs['B'], 'run-B', 'Nope', [2, 1, 0], ["Wrong.\nNo refund offered.", 'Owner']],
        ], array_map(static fn (array $answer): array => [
            $answer['letter'], $answer['runId'], $answer['label'], $answer['output'], array_column($answer['scores'], 'value'),
            null === $answer['comment'] ? null : [$answer['comment']['text'], $answer['comment']['by']],
        ], $view['answers']));
        self::assertNull($view['disagreement']);

        // c3: only A answered it, and its exact match could not run.
        $c3 = self::json(self::requestJson($client, 'GET', self::path($evaluation, "/cases/{$cases['c3']}")));
        self::assertSame(['A'], array_column($c3['answers'], 'letter'));
        self::assertSame(['criterionId' => $evaluation->getRubric()->getCriteria()[2]->getId()->toRfc4122(), 'value' => null, 'outcome' => 'check_error', 'errorCode' => 'missing_reference'], $c3['answers'][0]['scores'][2]);
        self::assertNull($c3['case']['reference']);

        // With the pair: the case's place among the disagreements, each case once, in the order of its largest.
        $pair = "?runA={$runs['A']}&runB={$runs['B']}";
        $first = self::json(self::requestJson($client, 'GET', self::path($evaluation, "/cases/{$cases['c1']}$pair")))['disagreement'];
        self::assertSame([1, 2, 'Exact', 1, 0, -100, null, $cases['c2']], [$first['position'], $first['count'], $first['criterion']['name'], $first['a'], $first['b'], $first['difference'], $first['previousCaseId'], $first['nextCaseId']]);
        $second = self::json(self::requestJson($client, 'GET', self::path($evaluation, "/cases/{$cases['c2']}$pair")))['disagreement'];
        self::assertSame([2, 2, 'Correct', 0, 1, 100, $cases['c1'], null], [$second['position'], $second['count'], $second['criterion']['name'], $second['a'], $second['b'], $second['difference'], $second['previousCaseId'], $second['nextCaseId']]);
        self::assertNull(self::json(self::requestJson($client, 'GET', self::path($evaluation, "/cases/{$cases['c3']}$pair")))['disagreement']);

        // A case of another dataset version is not one of this evaluation's.
        self::assertProblem(self::requestJson($client, 'GET', self::path($evaluation, '/cases/'.self::caseIds($other)['c1'])), 404, 'not_found');
    }

    /**
     * One row per answer, case by case and run by run: the case, the run, the answer, each criterion's score (1–5 as a
     * number, pass or fail, check_error:<code>, empty while not given), the comment and who wrote it.
     */
    public function testTheExportHasEveryAnswerWithItsScoresAsCsv(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $evaluation = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false);
        $tone = $evaluation->getRubric()->getCriteria()[0];
        self::service(Connection::class, 'doctrine.dbal.default_connection')->executeStatement('DELETE FROM score WHERE evaluation_item_id = ? AND criterion_id = ?', [self::itemId($evaluation, 'B', 'c2'), $tone->getId()->toRfc4122()]);
        $client->loginUser($fixture['owner']);
        // A comment that a spreadsheet would run, with quotes and a line break.
        $comment = "=HYPERLINK(\"https://example.com\")\nSecond line";
        self::requestJson($client, 'PUT', self::path($evaluation, '/items/'.self::itemId($evaluation, 'B', 'c1').'/comment'), ['comment' => $comment]);

        $client->request('GET', self::path($evaluation, '/export.csv'));

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=results-check-results.csv', $response->headers->get('Content-Disposition'));
        $csv = $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}case_key,tags,input,reference,run_letter,run_label,output,Tone,Correct,Exact,comment,comment_by\r\n", $csv);
        // The app's numbers stay numbers; the formula in the comment gets its quote, inside the quoted cell. (PHP's
        // fputcsv also quotes a cell with a space: RFC 4180 allows quotes around any cell.)
        self::assertStringContainsString("\r\nc1,billing|refund,\"Input c1\",\"Reference c1\",A,run-A,\"reference c1\",5,pass,pass,,\r\n", $csv);
        self::assertStringContainsString(",\"'=HYPERLINK(\"\"https://example.com\"\")\nSecond line\",Owner\r\n", $csv);

        $stream = fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (false !== $row = fgetcsv($stream, escape: '')) {
            $rows[] = $row;
        }
        self::assertSame([
            ['case_key', 'tags', 'input', 'reference', 'run_letter', 'run_label', 'output', 'Tone', 'Correct', 'Exact', 'comment', 'comment_by'],
            ['c1', 'billing|refund', 'Input c1', 'Reference c1', 'A', 'run-A', 'reference c1', '5', 'pass', 'pass', '', ''],
            ['c1', 'billing|refund', 'Input c1', 'Reference c1', 'B', 'run-B', 'Nope', '2', 'pass', 'fail', "'$comment", 'Owner'],
            ['c2', 'billing', 'Input c2', 'Reference c2', 'A', 'run-A', 'Something else', '3', 'fail', 'fail', '', ''],
            ['c2', 'billing', 'Input c2', 'Reference c2', 'B', 'run-B', 'Reference c2', '', 'pass', 'pass', '', ''],
            ['c3', '', 'Input c3', '', 'A', 'run-A', 'Anything', '4', 'pass', 'check_error:missing_reference', '', ''],
        ], $rows);
    }

    public function testAnEvaluatorReadsTheResultsOfABlindEvaluationOnlyOnceItIsClosed(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $blind = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: true);
        $open = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false, name: 'Not blind');
        // The results, the comparison, a case and the export: each answers 403 to an evaluator while the evaluation is
        // blind and open.
        $routes = static function (Evaluation $evaluation): array {
            $runs = self::runIds($evaluation);

            return ['/results', "/comparison?runA={$runs['A']}&runB={$runs['B']}", '/cases/'.self::caseIds($evaluation)['c1'], '/export.csv'];
        };
        $read = static function (string $actor, Evaluation $evaluation) use ($client, $fixture, $routes): array {
            $user = $fixture[$actor];
            self::assertInstanceOf(User::class, $user);
            $client->loginUser($user);

            return array_map(static fn (string $route): int => self::requestJson($client, 'GET', self::path($evaluation, $route))->getStatusCode(), $routes($evaluation));
        };

        $client->loginUser($fixture['evaluator']);
        foreach ($routes($blind) as $route) {
            self::assertProblem(self::requestJson($client, 'GET', self::path($blind, $route)), 403, 'results_hidden_until_closed');
        }
        self::assertSame([[200, 200, 200, 200], [200, 200, 200, 200], [200, 200, 200, 200]], [$read('owner', $blind), $read('viewer', $blind), $read('evaluator', $open)]);

        $client->loginUser($fixture['owner']);
        self::assertSame(200, self::requestJson($client, 'POST', self::path($blind, '/close'))->getStatusCode());
        self::assertSame([200, 200, 200, 200], $read('evaluator', $blind));
    }

    private static function path(Evaluation $evaluation, string $rest): string
    {
        return "/api/projects/{$evaluation->getProject()->getId()}/evaluations/{$evaluation->getId()}$rest";
    }

    /** @return array<string, string> [letter] = run id */
    private static function runIds(Evaluation $evaluation): array
    {
        /** @var array<string, string> $ids */
        $ids = self::service(Connection::class, 'doctrine.dbal.default_connection')->fetchAllKeyValue('SELECT letter, run_id FROM evaluation_run WHERE evaluation_id = ? ORDER BY letter', [$evaluation->getId()->toRfc4122()]);

        return $ids;
    }

    /** @return array<string, string> [case key] = case id */
    private static function caseIds(Evaluation $evaluation): array
    {
        /** @var array<string, string> $ids */
        $ids = self::service(Connection::class, 'doctrine.dbal.default_connection')->fetchAllKeyValue('SELECT case_key, id FROM test_case WHERE dataset_version_id = ?', [$evaluation->getDatasetVersion()->getId()->toRfc4122()]);

        return $ids;
    }

    private static function itemId(Evaluation $evaluation, string $letter, string $key): string
    {
        return (string) self::service(Connection::class, 'doctrine.dbal.default_connection')->fetchOne(
            'SELECT i.id FROM evaluation_item i JOIN answer a ON a.id = i.answer_id JOIN test_case c ON c.id = a.test_case_id
            JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id
            WHERE i.evaluation_id = ? AND r.letter = ? AND c.case_key = ?',
            [$evaluation->getId()->toRfc4122(), $letter, $key],
        );
    }

    /**
     * @param list<array<string, mixed>> $criteria
     *
     * @return list<list<mixed>> per criterion: its name or id (and measure), A and B as [n, value, check errors], B − A
     */
    private static function sides(array $criteria, string $by): array
    {
        return array_map(static fn (array $criterion): array => [
            $criterion[$by],
            ...('name' === $by ? [$criterion['measure']] : []),
            [$criterion['a']['n'], $criterion['a']['value'], $criterion['a']['checkErrors']],
            [$criterion['b']['n'], $criterion['b']['value'], $criterion['b']['checkErrors']],
            $criterion['difference'],
        ], $criteria);
    }

    /**
     * @param array<string, mixed> $comparison
     *
     * @return array<list<mixed>> case key, criterion, A, B, B − A in the criterion's units, and from 0 to 100
     */
    private static function disagreements(array $comparison): array
    {
        return array_map(
            static fn (array $row): array => [$row['case']['key'], $row['criterion']['name'], $row['a'], $row['b'], $row['difference'], $row['normalised']],
            $comparison['disagreements'],
        );
    }

    /** The evaluation above, with every score given as MANUAL says and the automatic checks run. */
    private static function scoredEvaluation(Project $project, User $owner, bool $blind, string $name = 'Results check'): Evaluation
    {
        $entityManager = self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
        $dataset = new Dataset($project, "Cases for $name");
        $version = new DatasetVersion($dataset, 1, 3, 'cases.csv', str_repeat('0', 64));
        $entityManager->persist($dataset);
        $entityManager->persist($version);
        $cases = [];
        foreach (['c1' => ['billing', 'refund'], 'c2' => ['billing'], 'c3' => []] as $key => $tags) {
            $cases[$key] = new TestCase($version, \count($cases) + 1, $key, "Input $key", 'c3' === $key ? null : "Reference $key", $tags);
            $entityManager->persist($cases[$key]);
        }
        $runs = [];
        foreach (self::OUTPUTS as $letter => $outputs) {
            $runs[$letter] = new Run($project, $version, "run-$letter", '', \count($outputs), 'run.csv', str_repeat('0', 64));
            $entityManager->persist($runs[$letter]);
            foreach ($outputs as $key => $output) {
                $entityManager->persist(new Answer($runs[$letter], $cases[$key], $output));
            }
        }
        $rubric = new Rubric($project, "Rubric for $name");
        $rubric->replaceCriteria([
            new CriterionData(CriterionKind::Scale1To5, 'Tone', '1: rude. 5: warm.', 2.0),
            new CriterionData(CriterionKind::PassFail, 'Correct', 'Pass when right.'),
            new CriterionData(CriterionKind::Automatic, 'Exact', weight: 0.5, checkType: CheckType::ExactMatch, checkParams: ['ignoreCase' => true]),
        ]);
        $entityManager->persist($rubric);
        $entityManager->flush();
        $evaluation = self::createEvaluation($rubric, array_values($runs), blind: $blind, name: $name);

        $scores = self::service(ScoreRepository::class);
        [$toneCriterion, $correctCriterion] = $rubric->getCriteria();
        foreach (self::MANUAL as $letter => $byCase) {
            foreach ($byCase as $key => [$tone, $correct]) {
                $item = $entityManager->getRepository(EvaluationItem::class)->findOneBy(['evaluation' => $evaluation, 'answer' => $entityManager->getRepository(Answer::class)->findOneBy(['run' => $runs[$letter], 'testCase' => $cases[$key]])]);
                self::assertInstanceOf(EvaluationItem::class, $item);
                $scores->saveManual($item, $toneCriterion, $tone, $owner);
                $scores->saveManual($item, $correctCriterion, $correct, $owner);
            }
        }
        $job = new CheckJob($evaluation, 5);
        $entityManager->persist($job);
        $entityManager->flush();
        self::service(RunAutomaticChecksHandler::class)(new RunAutomaticChecks($job->getId()->toRfc4122()));
        self::assertSame('succeeded', self::service(Connection::class, 'doctrine.dbal.default_connection')->fetchOne('SELECT status FROM check_job WHERE id = ?', [$job->getId()->toRfc4122()]));

        return $evaluation;
    }

    /**
     * @param array<string, mixed> $criterion
     *
     * @return array<array<mixed>> letter, n, mean, pass rate, counts and check errors, per run
     */
    private static function measures(array $criterion): array
    {
        return array_map(
            static fn (array $run): array => [$run['letter'], $run['n'], $run['mean'], $run['passRate'], array_column($run['distribution'], 'count'), $run['checkErrors']],
            $criterion['runs'],
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class, ?string $id = null): object
    {
        $service = self::getContainer()->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
