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

    public function testAnEvaluatorReadsTheResultsOfABlindEvaluationOnlyOnceItIsClosed(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $blind = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: true);
        $open = self::scoredEvaluation($fixture['project'], $fixture['owner'], blind: false, name: 'Not blind');
        $path = "/api/projects/{$fixture['project']->getId()}/evaluations";
        $read = static function (string $actor, Evaluation $evaluation) use ($client, $fixture, $path): int {
            $user = $fixture[$actor];
            self::assertInstanceOf(User::class, $user);
            $client->loginUser($user);

            return self::requestJson($client, 'GET', "$path/{$evaluation->getId()}/results")->getStatusCode();
        };

        $client->loginUser($fixture['evaluator']);
        self::assertProblem(self::requestJson($client, 'GET', "$path/{$blind->getId()}/results"), 403, 'results_hidden_until_closed');
        self::assertSame([200, 200, 200], [$read('owner', $blind), $read('viewer', $blind), $read('evaluator', $open)]);

        $client->loginUser($fixture['owner']);
        self::assertSame(200, self::requestJson($client, 'POST', "$path/{$blind->getId()}/close")->getStatusCode());
        self::assertSame(200, $read('evaluator', $blind));
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
