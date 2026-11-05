<?php

declare(strict_types=1);

namespace App\Tests\Evaluations;

use App\Datasets\Entity\Answer;
use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Datasets\Entity\TestCase;
use App\Evaluations\Checks\RunAutomaticChecks;
use App\Evaluations\Checks\RunAutomaticChecksHandler;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Repository\ScoreRepository;
use App\Projects\Entity\Project;
use App\Tests\ApiTestCase;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Check jobs (architecture §7): dispatched after the commit, run by the worker in batches, idempotent, and run again on
 * request. The async transport is in memory in tests.
 */
final class CheckJobTest extends ApiTestCase
{
    public function testCreatingAnEvaluationDispatchesItsJobAfterTheCommitAndTheWorkerRunsIt(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::version($fixture['project'], ['c1' => 'Paris', 'c2' => null]);
        $baseline = self::runOf($version, 'baseline', ['c1' => ' paris ', 'c2' => '{"refund": true}']);
        $candidate = self::runOf($version, 'candidate', ['c1' => 'Lyon']);
        $rubric = self::rubric($fixture['project'], [
            ['Same city', CheckType::ExactMatch, ['ignoreCase' => true]],
            ['Mentions a refund', CheckType::Contains, ['text' => 'refund', 'ignoreCase' => false]],
            ['JSON', CheckType::ValidJson, []],
        ]);
        // The transaction nesting level at each dispatch: 0 is after the commit (the test's own transaction is below DBAL).
        $levels = [];
        $client->disableReboot();
        self::eventDispatcher($client)->addListener(SendMessageToTransportsEvent::class, static function () use (&$levels): void {
            $levels[] = self::connection()->getTransactionNestingLevel();
        });

        $response = self::requestJson($client, 'POST', "$path/evaluations", [
            'name' => 'Weekly check',
            'datasetVersion' => $version->getId()->toRfc4122(),
            'runs' => [$baseline->getId()->toRfc4122(), $candidate->getId()->toRfc4122()],
            'rubric' => $rubric->getId()->toRfc4122(),
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $evaluation = self::json($response);
        self::assertSame(['status' => 'queued', 'processed' => 0, 'total' => 3, 'errorCode' => null], $evaluation['checkJob']);
        $jobId = self::jobIds($evaluation['id'])[0];
        self::assertEquals([new RunAutomaticChecks($jobId)], self::sentMessages());
        self::assertSame([0], $levels, 'Dispatched once, after the commit');

        self::consume();

        $evaluation = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}"));
        self::assertSame(['status' => 'succeeded', 'processed' => 3, 'total' => 3, 'errorCode' => null], $evaluation['checkJob']);
        self::assertSame([
            ['baseline', 'c1', 'Same city', 1, 'scored', null],
            ['baseline', 'c1', 'Mentions a refund', 0, 'scored', null],
            ['baseline', 'c1', 'JSON', 0, 'scored', null],
            ['baseline', 'c2', 'Same city', null, 'check_error', 'missing_reference'],
            ['baseline', 'c2', 'Mentions a refund', 1, 'scored', null],
            ['baseline', 'c2', 'JSON', 1, 'scored', null],
            ['candidate', 'c1', 'Same city', 0, 'scored', null],
            ['candidate', 'c1', 'Mentions a refund', 0, 'scored', null],
            ['candidate', 'c1', 'JSON', 0, 'scored', null],
        ], self::automaticScores($evaluation['id']));
        // Progress counts manual scores only: 3 items × Tone, none scored yet; the item shows the checks, by no one.
        self::assertSame(['scored' => 0, 'total' => 3], $evaluation['progress']);
        $item = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}/items/next"));
        self::assertSame(['scored' => 0, 'total' => 3], $item['progress']);
        self::assertCount(3, $item['scores']);
        foreach ($item['scores'] as $score) {
            self::assertSame(['automatic', null], [$score['source'], $score['scoredBy']]);
        }
    }

    public function testARefusedEvaluationDispatchesNothing(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::version($fixture['project'], ['c1' => 'Paris']);
        $rubric = self::rubric($fixture['project'], [['JSON', CheckType::ValidJson, []]]);

        $response = self::requestJson($client, 'POST', "$path/evaluations", [
            'name' => 'Weekly check', 'datasetVersion' => $version->getId()->toRfc4122(), 'runs' => [], 'rubric' => $rubric->getId()->toRfc4122(),
        ]);

        self::assertProblem($response, 422, 'validation_failed');
        self::assertSame([], self::sentMessages());
    }

    public function testRunningAJobAgainGivesTheSameRows(): void
    {
        [$evaluationId, $jobId] = self::evaluationWithJob();
        self::handle($jobId);
        $first = self::automaticScores($evaluationId);

        self::handle($jobId); // the message delivered twice: the job is done, nothing changes
        self::connection()->executeStatement("UPDATE check_job SET status = 'running' WHERE id = ?", [$jobId]);
        self::handle($jobId); // a worker stopped half-way: the message comes back and the job runs from the start

        self::assertCount(6, $first);
        self::assertSame($first, self::automaticScores($evaluationId));
        self::assertSame(6, (int) self::connection()->fetchOne('SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ?', [$evaluationId]));
        self::assertSame(['succeeded', 3, 3, null], self::job($jobId));
    }

    public function testTheJobGoesThroughItsItemsInBatches(): void
    {
        [$evaluationId, $jobId] = self::evaluationWithJob();
        $handler = new RunAutomaticChecksHandler(self::connection(), self::service(ScoreRepository::class), batchSize: 2);

        $handler(new RunAutomaticChecks($jobId));

        self::assertSame(['succeeded', 3, 3, null], self::job($jobId));
        self::assertCount(6, self::automaticScores($evaluationId));
    }

    public function testAnExceptionLeavesTheJobFailedWithItsErrorCode(): void
    {
        [, $jobId] = self::evaluationWithJob();
        // Parameters that a saved rubric never has: the check throws.
        self::connection()->executeStatement("UPDATE criterion SET check_params = '{}' WHERE check_type = 'contains'");

        try {
            self::handle($jobId);
            self::fail('The exception goes back to Messenger, which retries the message');
        } catch (\UnexpectedValueException) {
        }

        self::assertSame(['failed', 0, 3, 'unexpected_error'], self::job($jobId));
    }

    public function testAMessageForAnOlderOrDeletedJobDoesNothing(): void
    {
        [$evaluationId, $jobId] = self::evaluationWithJob();
        self::connection()->executeStatement("UPDATE check_job SET status = 'failed', created_at = created_at - INTERVAL '1 minute' WHERE id = ?", [$jobId]);
        self::connection()->executeStatement("INSERT INTO check_job (id, evaluation_id, status, processed, total, created_at) VALUES (gen_random_uuid(), ?, 'queued', 0, 3, NOW())", [$evaluationId]);

        self::handle($jobId);
        self::handle('0199e0a4-0000-7000-8000-000000000000');

        self::assertSame(['failed', 0, 3, null], self::job($jobId));
        self::assertSame([], self::automaticScores($evaluationId));
    }

    public function testTheOwnerRunsTheChecksAgain(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        [$evaluationId, $jobId] = self::evaluationWithJob($fixture['project']);
        $checks = "$path/evaluations/$evaluationId/automatic-checks";

        // A queued job is dispatched again, not duplicated: that also recovers a job whose dispatch failed.
        $response = self::requestJson($client, 'POST', $checks);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['status' => 'queued', 'processed' => 0, 'total' => 3, 'errorCode' => null], self::json($response)['checkJob']);
        self::assertSame([$jobId], self::jobIds($evaluationId));
        self::assertEquals([new RunAutomaticChecks($jobId)], self::sentMessages());

        self::connection()->executeStatement("UPDATE check_job SET status = 'running' WHERE id = ?", [$jobId]);
        self::assertProblem(self::requestJson($client, 'POST', $checks), 409, 'check_job_running');

        // A finished job, succeeded or failed: a new one.
        foreach (['succeeded', 'failed'] as $status) {
            self::connection()->executeStatement('UPDATE check_job SET status = ? WHERE evaluation_id = ?', [$status, $evaluationId]);
            $response = self::requestJson($client, 'POST', $checks);
            self::assertSame(202, $response->getStatusCode());
            $jobIds = self::jobIds($evaluationId);
            self::assertSame('queued', self::json($response)['checkJob']['status']);
            self::assertEquals([new RunAutomaticChecks($jobIds[0])], self::sentMessages());
            self::handle($jobIds[0]);
        }
        self::assertCount(3, self::jobIds($evaluationId));
        self::assertCount(6, self::automaticScores($evaluationId));
    }

    public function testRunningTheChecksAgainNeedsAnOpenEvaluationWithAutomaticCriteria(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::version($fixture['project'], ['c1' => 'Paris']);
        $run = self::runOf($version, 'baseline', ['c1' => 'Paris']);
        $closed = self::createEvaluation(self::rubric($fixture['project'], [['JSON', CheckType::ValidJson, []]]), [$run], closed: true, name: 'Closed');
        $manual = self::createEvaluation(self::rubric($fixture['project'], [], 'Manual'), [$run], name: 'Manual');

        self::assertProblem(self::requestJson($client, 'POST', "$path/evaluations/{$closed->getId()}/automatic-checks"), 409, 'evaluation_closed');
        self::assertProblem(self::requestJson($client, 'POST', "$path/evaluations/{$manual->getId()}/automatic-checks"), 422, 'no_automatic_criteria');
        self::assertSame([], self::sentMessages());
    }

    /**
     * The owner of a new project, signed in. Returns the client, the members and the project's path.
     *
     * @return array{KernelBrowser, array{project: Project, owner: User, evaluator: User, viewer: User, non-member: User}, string}
     */
    private static function signedInOwner(): array
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['owner']);

        return [$client, $fixture, '/api/projects/'.$fixture['project']->getId()];
    }

    /**
     * An evaluation of one run (c1 "Paris" answered "paris", c2 without a reference answered "{}", c3 "Lyon" answered
     * "A refund") with two automatic criteria and a queued job, as the API leaves it.
     *
     * @return array{string, string} the evaluation and job ids
     */
    private static function evaluationWithJob(?Project $project = null): array
    {
        $project ??= self::createProjectWithMembers()['project'];
        $version = self::version($project, ['c1' => 'Paris', 'c2' => null, 'c3' => 'Lyon']);
        $run = self::runOf($version, 'baseline', ['c1' => 'paris', 'c2' => '{}', 'c3' => 'A refund']);
        $rubric = self::rubric($project, [
            ['Same city', CheckType::ExactMatch, ['ignoreCase' => true]],
            ['Mentions a refund', CheckType::Contains, ['text' => 'refund', 'ignoreCase' => false]],
        ]);
        $evaluationId = self::createEvaluation($rubric, [$run])->getId()->toRfc4122();
        self::connection()->executeStatement("INSERT INTO check_job (id, evaluation_id, status, processed, total, created_at) VALUES (gen_random_uuid(), ?, 'queued', 0, 3, NOW() - INTERVAL '1 hour')", [$evaluationId]);

        return [$evaluationId, self::jobIds($evaluationId)[0]];
    }

    /**
     * @param array<string, string|null> $references by case key
     */
    private static function version(Project $project, array $references): DatasetVersion
    {
        $entityManager = self::entityManager();
        $dataset = new Dataset($project, 'Support');
        $version = new DatasetVersion($dataset, 1, \count($references), 'cases.csv', str_repeat('0', 64));
        $entityManager->persist($dataset);
        $entityManager->persist($version);
        $position = 0;
        foreach ($references as $key => $reference) {
            $entityManager->persist(new TestCase($version, ++$position, $key, "Input $key", $reference));
        }
        $entityManager->flush();

        return $version;
    }

    /**
     * @param array<string, string> $outputs by case key
     */
    private static function runOf(DatasetVersion $version, string $label, array $outputs): Run
    {
        $entityManager = self::entityManager();
        $run = new Run($version->getDataset()->getProject(), $version, $label, '', \count($outputs), 'run.csv', str_repeat('0', 64));
        $entityManager->persist($run);
        foreach ($outputs as $key => $output) {
            $case = $entityManager->getRepository(TestCase::class)->findOneBy(['datasetVersion' => $version, 'caseKey' => $key]);
            self::assertInstanceOf(TestCase::class, $case);
            $entityManager->persist(new Answer($run, $case, $output));
        }
        $entityManager->flush();

        return $run;
    }

    /**
     * A rubric with a manual criterion, Tone, then the automatic ones.
     *
     * @param list<array{string, CheckType, array<string, mixed>}> $checks name, type and parameters
     */
    private static function rubric(Project $project, array $checks, string $name = 'Quality'): Rubric
    {
        $rubric = new Rubric($project, $name);
        $rubric->replaceCriteria([
            new CriterionData(CriterionKind::Scale1To5, 'Tone', '1: rude. 5: warm.'),
            ...array_map(static fn (array $check): CriterionData => new CriterionData(CriterionKind::Automatic, $check[0], checkType: $check[1], checkParams: $check[2]), $checks),
        ]);
        self::entityManager()->persist($rubric);
        self::entityManager()->flush();

        return $rubric;
    }

    private static function handle(string $jobId): void
    {
        self::service(RunAutomaticChecksHandler::class)(new RunAutomaticChecks($jobId));
    }

    /**
     * Runs the worker's command, as Compose does, for one message. Not through a console Application: booting it
     * again would reset the services, and with them the in-memory queue.
     */
    private static function consume(): void
    {
        $command = self::getContainer()->get('console.command.messenger_consume_messages');
        self::assertInstanceOf(Command::class, $command);
        $tester = new CommandTester($command);
        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 10]);
        $tester->assertCommandIsSuccessful();
    }

    /**
     * The messages sent to the async transport by the last request (each request reboots the kernel, and with it the
     * transport, unless the client does not reboot).
     *
     * @return list<object>
     */
    private static function sentMessages(): array
    {
        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), array_values(self::transport()->getSent()));
    }

    private static function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<string> newest first
     */
    private static function jobIds(string $evaluationId): array
    {
        /** @var list<string> $ids */
        $ids = self::connection()->fetchFirstColumn('SELECT id FROM check_job WHERE evaluation_id = ? ORDER BY created_at DESC, id DESC', [$evaluationId]);

        return $ids;
    }

    /**
     * @return array{string, int, int, string|null} status, processed, total and error code
     */
    private static function job(string $jobId): array
    {
        $job = self::connection()->fetchAssociative('SELECT status, processed, total, error_code FROM check_job WHERE id = ?', [$jobId]);
        self::assertIsArray($job);

        return [(string) $job['status'], (int) $job['processed'], (int) $job['total'], null === $job['error_code'] ? null : (string) $job['error_code']];
    }

    /**
     * Run, case, criterion, value, outcome and error code, by run, case and criterion.
     *
     * @return list<array{string, string, string, int|null, string, string|null}>
     */
    private static function automaticScores(string $evaluationId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['label'], (string) $row['case_key'], (string) $row['name'], null === $row['value'] ? null : (int) $row['value'], (string) $row['outcome'], null === $row['error_code'] ? null : (string) $row['error_code']],
            self::connection()->fetchAllAssociative(
                "SELECT r.label, c.case_key, cr.name, s.value, s.outcome, s.error_code FROM score s
                    JOIN evaluation_item i ON i.id = s.evaluation_item_id JOIN answer a ON a.id = i.answer_id
                    JOIN run r ON r.id = a.run_id JOIN test_case c ON c.id = a.test_case_id JOIN criterion cr ON cr.id = s.criterion_id
                WHERE i.evaluation_id = ? AND s.source = 'automatic' AND s.scored_by_id IS NULL
                ORDER BY r.label, c.position, cr.position",
                [$evaluationId],
            ),
        );
    }

    private static function eventDispatcher(KernelBrowser $client): EventDispatcherInterface
    {
        $dispatcher = $client->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
