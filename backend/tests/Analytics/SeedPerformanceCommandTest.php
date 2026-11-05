<?php

declare(strict_types=1);

namespace App\Tests\Analytics;

use App\Analytics\Command\SeedPerformanceCommand;
use App\Evaluations\Checks\RunAutomaticChecksHandler;
use App\Evaluations\Repository\EvaluationItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedPerformanceCommandTest extends KernelTestCase
{
    public function testItRefusesToRunOutsideDevAndTest(): void
    {
        $tester = new CommandTester(self::command('prod'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('only runs in dev and test', $tester->getDisplay());
    }

    /** A small run: 5 runs of 20 cases, 6 criteria (3 of them automatic), every score given. */
    public function testItSeedsAnEvaluationWithEveryScore(): void
    {
        $tester = new CommandTester(self::command('test'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--cases' => '20']));

        $connection = self::service(Connection::class, 'doctrine.dbal.default_connection');
        $evaluation = (string) $connection->fetchOne("SELECT id FROM evaluation WHERE name = 'Five models at scale' ORDER BY created_at DESC LIMIT 1");
        self::assertSame(100, (int) $connection->fetchOne('SELECT COUNT(*) FROM evaluation_item WHERE evaluation_id = ?', [$evaluation]));
        self::assertSame(600, (int) $connection->fetchOne('SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ?', [$evaluation]));
        self::assertSame('succeeded', $connection->fetchOne('SELECT status FROM check_job WHERE evaluation_id = ?', [$evaluation]));
        // Every 10th case has no reference: exact_match gives a check error on its 5 answers.
        self::assertSame(10, (int) $connection->fetchOne("SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ? AND s.outcome = 'check_error'", [$evaluation]));
        self::assertStringContainsString('20 cases, 100 answers, 600 scores', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private static function command(string $environment): SeedPerformanceCommand
    {
        return new SeedPerformanceCommand(
            self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager'),
            self::service(Connection::class, 'doctrine.dbal.default_connection'),
            self::service(EvaluationItemRepository::class),
            self::service(RunAutomaticChecksHandler::class),
            $environment,
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
