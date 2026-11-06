<?php

declare(strict_types=1);

namespace App\Tests\Analytics;

use App\Analytics\Command\DemoCommand;
use App\Datasets\Import\DatasetImporter;
use App\Evaluations\Checks\RunAutomaticChecks;
use App\Evaluations\Checks\RunAutomaticChecksHandler;
use App\Evaluations\Repository\EvaluationItemRepository;
use App\Users\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The demo data (MVP criterion 4): what app:demo makes, that a second run changes nothing, and that prod is refused. */
final class DemoCommandTest extends KernelTestCase
{
    public function testItRefusesToRunOutsideDevAndTest(): void
    {
        $tester = new CommandTester(self::command('prod'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('only runs in dev and test', $tester->getDisplay());
        self::assertSame(0, self::number("SELECT COUNT(*) FROM users WHERE email LIKE 'demo.%@example.com'"));
    }

    public function testItLoadsTheDemoOnceAndThenChangesNothing(): void
    {
        $tester = new CommandTester(self::command('test'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        // Three accounts, members of one project with their roles.
        self::assertSame(
            [[DemoCommand::EVALUATOR, 'evaluator'], [DemoCommand::OWNER, 'owner'], [DemoCommand::VIEWER, 'viewer']],
            array_map(static fn (array $row): array => array_values($row), self::rows('SELECT u.email, m.role FROM users u JOIN project_member m ON m.user_id = u.id JOIN project p ON p.id = m.project_id WHERE p.name = ? ORDER BY u.email', [DemoCommand::PROJECT])),
        );
        // 50 cases (4 without a reference), and 2 runs, the second one partial.
        self::assertSame(50, self::number('SELECT case_count FROM dataset_version v JOIN dataset d ON d.id = v.dataset_id JOIN project p ON p.id = d.project_id WHERE p.name = ?', [DemoCommand::PROJECT]));
        self::assertSame(4, self::number('SELECT COUNT(*) FROM test_case c JOIN dataset_version v ON v.id = c.dataset_version_id JOIN dataset d ON d.id = v.dataset_id JOIN project p ON p.id = d.project_id WHERE p.name = ? AND c.reference IS NULL', [DemoCommand::PROJECT]));
        self::assertSame([['baseline-v1', 50], ['tuned-v2', 46]], array_map(static fn (array $row): array => [$row['label'], (int) $row['answer_count']], self::rows('SELECT r.label, r.answer_count FROM run r JOIN project p ON p.id = r.project_id WHERE p.name = ? ORDER BY r.label', [DemoCommand::PROJECT])));
        self::assertSame(
            ['scale_1_5 ', 'pass_fail ', 'automatic regex', 'automatic exact_match', 'automatic length'],
            array_column(self::rows("SELECT c.kind || ' ' || COALESCE(c.check_type, '') AS k FROM criterion c JOIN rubric r ON r.id = c.rubric_id JOIN project p ON p.id = r.project_id WHERE p.name = ? ORDER BY c.position", [DemoCommand::PROJECT]), 'k'),
        );

        // Blind and open, partly scored (54 answers of 96, both manual criteria), with comments; its checks go to the
        // worker.
        $open = self::evaluation('Baseline vs tuned');
        self::assertSame([true, 'open', 96], [$open['blind'], $open['status'], self::number('SELECT COUNT(*) FROM evaluation_item WHERE evaluation_id = ?', [$open['id']])]);
        self::assertSame(108, self::number("SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ? AND s.source = 'manual'", [$open['id']]));
        self::assertGreaterThan(0, self::number('SELECT COUNT(*) FROM evaluation_item WHERE evaluation_id = ? AND comment IS NOT NULL', [$open['id']]));
        self::assertSame('queued', self::connection()->fetchOne('SELECT status FROM check_job WHERE evaluation_id = ?', [$open['id']]));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof RunAutomaticChecks));

        // Closed, with every score: 96 × 2 manual, 96 × 3 automatic. The 6 answers to a case without a reference get a
        // check error on the exact match (4 cases for the baseline, 2 that the tuned run answered).
        $closed = self::evaluation('Release check');
        self::assertSame([false, 'closed'], [$closed['blind'], $closed['status']]);
        self::assertSame('succeeded', self::connection()->fetchOne('SELECT status FROM check_job WHERE evaluation_id = ?', [$closed['id']]));
        self::assertSame(
            [['automatic', 288], ['manual', 192]],
            array_map(static fn (array $row): array => [$row['source'], (int) $row['n']], self::rows('SELECT s.source, COUNT(*) AS n FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ? GROUP BY s.source ORDER BY s.source', [$closed['id']])),
        );
        self::assertSame(6, self::number("SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ? AND s.outcome = 'check_error'", [$closed['id']]));
        // The story the demo tells: the tuned run is kinder and keeps to the rules more often.
        $tone = self::rows("SELECT r.label, AVG(s.value) AS mean FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id JOIN answer a ON a.id = i.answer_id
            JOIN run r ON r.id = a.run_id JOIN criterion c ON c.id = s.criterion_id WHERE i.evaluation_id = ? AND c.name = 'Tone' GROUP BY r.label ORDER BY r.label", [$closed['id']]);
        self::assertLessThan((float) $tone[1]['mean'], (float) $tone[0]['mean']);

        // Again: nothing new, and it says why.
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('already there', $tester->getDisplay());
        self::assertSame(3, self::number("SELECT COUNT(*) FROM users WHERE email LIKE 'demo.%@example.com'"));
        self::assertSame(1, self::number('SELECT COUNT(*) FROM project WHERE name = ?', [DemoCommand::PROJECT]));
        self::assertSame(2, self::number('SELECT COUNT(*) FROM evaluation e JOIN project p ON p.id = e.project_id WHERE p.name = ?', [DemoCommand::PROJECT]));
    }

    private static function command(string $environment): DemoCommand
    {
        return new DemoCommand(
            self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager'),
            self::connection(),
            self::service(UserRepository::class),
            self::service(UserPasswordHasherInterface::class, 'security.user_password_hasher'),
            self::service(DatasetImporter::class),
            self::service(EvaluationItemRepository::class),
            self::service(RunAutomaticChecksHandler::class),
            self::service(MessageBusInterface::class, 'messenger.default_bus'),
            $environment,
            \dirname(__DIR__, 2).'/demo',
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

    /**
     * @return array{id: string, blind: bool, status: string}
     */
    private static function evaluation(string $name): array
    {
        $row = self::connection()->fetchAssociative('SELECT e.id, e.blind, e.status FROM evaluation e JOIN project p ON p.id = e.project_id WHERE p.name = ? AND e.name = ?', [DemoCommand::PROJECT, $name]);
        self::assertIsArray($row);

        return ['id' => (string) $row['id'], 'blind' => (bool) $row['blind'], 'status' => (string) $row['status']];
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(string $query, array $parameters = []): array
    {
        return self::connection()->fetchAllAssociative($query, $parameters);
    }

    /**
     * @param list<mixed> $parameters
     */
    private static function number(string $query, array $parameters = []): int
    {
        return (int) self::connection()->fetchOne($query, $parameters);
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
