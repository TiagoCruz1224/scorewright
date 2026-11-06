<?php

declare(strict_types=1);

namespace App\Evaluations\Checks;

use App\Evaluations\Entity\CheckType;
use App\Evaluations\Repository\ScoreRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a check job (architecture §7): every automatic criterion of the rubric on every item, in batches by position.
 * The scores are upserts, so running a job again (a retry, a message delivered twice, a worker stopped half-way) gives
 * the same rows.
 *
 * The job goes queued → running → succeeded. An exception goes back to Messenger, which tries the message 3 more times
 * and then keeps it in the failed transport: MarkFailedCheckJobs puts the job back to queued between tries, and sets it
 * failed after the last one. Each try starts the job again.
 *
 * - **One worker per job:** a session advisory lock on the job. A message delivered again (redeliver_timeout) while
 *   another worker still runs it finds the lock taken, and leaves the job to that worker. If that worker died, its
 *   database connection, and the lock, went with it, and the job runs again.
 * - **A time budget** (SWQ3-01). PCRE's backtrack limit stops exponential patterns, but not quadratic ones: `\s+$` or
 *   `(?=(a+))\1+$` on a 50,000-character answer take up to about 1 s each. So a criterion that has used up its own
 *   budget, and every criterion once the job has used up the job's, give check_error check_time_budget for the items
 *   left, without running. The job still ends succeeded: each of those cells says why.
 * - **Progress** is saved at least every second, with the scores so far, so a slow job never shows 0 for minutes.
 */
#[AsMessageHandler]
final class RunAutomaticChecksHandler
{
    public const BATCH = 500;

    /**
     * Seconds a whole job may spend checking. A job at the MVP limits (24,800 answers, 3 automatic criteria in
     * app:seed-performance) takes about 10 s, so this leaves 12 times that; and it stays well below the transport's
     * redeliver_timeout (300 s), after which the message would be delivered again.
     */
    public const JOB_BUDGET = 120.0;

    /** Seconds one criterion may spend: a single slow pattern cannot use up the job's budget for the others. */
    public const CRITERION_BUDGET = 30.0;

    public const TIME_BUDGET = 'check_time_budget';

    /** Seconds between two saves of the progress. */
    private const PROGRESS_EVERY = 1.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly ScoreRepository $scores,
        private readonly int $batchSize = self::BATCH,
        private readonly float $jobBudget = self::JOB_BUDGET,
        private readonly float $criterionBudget = self::CRITERION_BUDGET,
    ) {
    }

    public function __invoke(RunAutomaticChecks $message): void
    {
        $job = $this->connection->fetchAssociative(
            'SELECT j.evaluation_id, j.status, e.rubric_id, e.status AS evaluation_status,
                j.id = (SELECT l.id FROM check_job l WHERE l.evaluation_id = j.evaluation_id ORDER BY l.created_at DESC, l.id DESC LIMIT 1) AS latest
            FROM check_job j JOIN evaluation e ON e.id = j.evaluation_id WHERE j.id = ?',
            [$message->jobId],
        );
        // Deleted with its evaluation, already done by an earlier delivery, replaced by a newer job, or (a failed job retried
        // from the failed transport) of an evaluation closed since: closing is final, so nothing to do.
        if (false === $job || 'succeeded' === $job['status'] || true !== $job['latest'] || 'closed' === $job['evaluation_status']) {
            return;
        }
        $lock = 'check_job:'.$message->jobId;
        if (true !== $this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$lock])) {
            return;
        }

        try {
            $this->connection->executeStatement("UPDATE check_job SET status = 'running', processed = 0, error_code = NULL, progressed_at = now() WHERE id = ?", [$message->jobId]);
            $processed = $this->check((string) $job['evaluation_id'], $this->criteria((string) $job['rubric_id']), $message->jobId);
            $this->connection->executeStatement("UPDATE check_job SET status = 'succeeded', processed = ? WHERE id = ?", [$processed, $message->jobId]);
        } finally {
            $this->connection->executeStatement('SELECT pg_advisory_unlock(hashtext(?))', [$lock]);
        }
    }

    /**
     * @return list<array{id: string, type: CheckType, params: array<string, mixed>}>
     */
    private function criteria(string $rubricId): array
    {
        $criteria = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT id, check_type, check_params FROM criterion WHERE rubric_id = ? AND kind = 'automatic' ORDER BY position",
            [$rubricId],
        ) as $row) {
            /** @var array<string, mixed> $params */
            $params = json_decode((string) $row['check_params'], true, flags: \JSON_THROW_ON_ERROR);
            $criteria[] = ['id' => (string) $row['id'], 'type' => CheckType::from((string) $row['check_type']), 'params' => $params];
        }

        return $criteria;
    }

    /**
     * @param list<array{id: string, type: CheckType, params: array<string, mixed>}> $criteria
     *
     * @return int the items checked
     */
    private function check(string $evaluationId, array $criteria, string $jobId): int
    {
        $started = hrtime(true);
        $spent = array_fill_keys(array_column($criteria, 'id'), 0); // nanoseconds per criterion
        $saved = $started;
        $processed = 0;
        $position = -1;
        $scores = [];
        do {
            // Keyset pagination on the (evaluation_id, position) index.
            $items = $this->connection->fetchAllAssociative(
                'SELECT i.id, i.position, a.output, c.reference FROM evaluation_item i
                JOIN answer a ON a.id = i.answer_id JOIN test_case c ON c.id = a.test_case_id
                WHERE i.evaluation_id = ? AND i.position > ? ORDER BY i.position LIMIT '.$this->batchSize,
                [$evaluationId, $position],
            );
            foreach ($items as $item) {
                $overJob = hrtime(true) - $started > $this->jobBudget * 1e9;
                foreach ($criteria as $criterion) {
                    if ($overJob || $spent[$criterion['id']] > $this->criterionBudget * 1e9) {
                        $result = self::TIME_BUDGET;
                    } else {
                        $start = hrtime(true);
                        $result = Checks::run($criterion['type'], $criterion['params'], (string) $item['output'], null === $item['reference'] ? null : (string) $item['reference']);
                        $spent[$criterion['id']] += hrtime(true) - $start;
                    }
                    $scores[] = [(string) $item['id'], $criterion['id'], \is_bool($result) ? (int) $result : null, \is_string($result) ? $result : null];
                }
                $position = (int) $item['position'];
                ++$processed;
                if (hrtime(true) - $saved >= self::PROGRESS_EVERY * 1e9) {
                    $this->save($scores, $processed, $jobId);
                    [$scores, $saved] = [[], hrtime(true)];
                }
            }
            $this->save($scores, $processed, $jobId);
            [$scores, $saved] = [[], hrtime(true)];
        } while (\count($items) === $this->batchSize);

        return $processed;
    }

    /**
     * The scores so far and the progress, in one transaction.
     *
     * @param list<array{string, string, int|null, string|null}> $scores
     */
    private function save(array $scores, int $processed, string $jobId): void
    {
        $this->connection->transactional(function () use ($scores, $processed, $jobId): void {
            $this->scores->saveAutomatic($scores);
            $this->connection->executeStatement('UPDATE check_job SET processed = ?, progressed_at = now() WHERE id = ?', [$processed, $jobId]);
        });
    }
}
