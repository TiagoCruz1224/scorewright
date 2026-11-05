<?php

declare(strict_types=1);

namespace App\Evaluations\Checks;

use App\Evaluations\Entity\CheckType;
use App\Evaluations\Repository\ScoreRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a check job (architecture §7): every automatic criterion of the rubric on every item, in batches by position,
 * each batch with its scores and the job's progress in one transaction. The scores are upserts, so running a job again
 * (a retry, a message delivered twice, a worker stopped half-way) gives the same rows.
 *
 * The job goes queued → running → succeeded. An exception leaves it failed with error_code unexpected_error and goes back
 * to Messenger, which retries the message 3 times and then keeps it in the failed transport; a retry starts the job
 * again.
 */
#[AsMessageHandler]
final class RunAutomaticChecksHandler
{
    public const BATCH = 500;
    public const UNEXPECTED_ERROR = 'unexpected_error';

    public function __construct(
        private readonly Connection $connection,
        private readonly ScoreRepository $scores,
        private readonly int $batchSize = self::BATCH,
    ) {
    }

    public function __invoke(RunAutomaticChecks $message): void
    {
        $job = $this->connection->fetchAssociative(
            'SELECT j.evaluation_id, j.status, e.rubric_id,
                j.id = (SELECT l.id FROM check_job l WHERE l.evaluation_id = j.evaluation_id ORDER BY l.created_at DESC, l.id DESC LIMIT 1) AS latest
            FROM check_job j JOIN evaluation e ON e.id = j.evaluation_id WHERE j.id = ?',
            [$message->jobId],
        );
        // Deleted with its evaluation, already done by an earlier delivery, or replaced by a newer job: nothing to do.
        if (false === $job || 'succeeded' === $job['status'] || true !== $job['latest']) {
            return;
        }

        $this->connection->executeStatement("UPDATE check_job SET status = 'running', processed = 0, error_code = NULL WHERE id = ?", [$message->jobId]);
        try {
            $processed = $this->check((string) $job['evaluation_id'], $this->criteria((string) $job['rubric_id']), $message->jobId);
            $this->connection->executeStatement("UPDATE check_job SET status = 'succeeded', processed = ? WHERE id = ?", [$processed, $message->jobId]);
        } catch (\Throwable $exception) {
            try {
                $this->connection->executeStatement("UPDATE check_job SET status = 'failed', error_code = ? WHERE id = ?", [self::UNEXPECTED_ERROR, $message->jobId]);
            } catch (\Throwable) {
                // The database is the problem: the job stays running, and Messenger's retry starts it again.
            }
            throw $exception;
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
        $processed = 0;
        $position = -1;
        do {
            // Keyset pagination on the (evaluation_id, position) index.
            $items = $this->connection->fetchAllAssociative(
                'SELECT i.id, i.position, a.output, c.reference FROM evaluation_item i
                JOIN answer a ON a.id = i.answer_id JOIN test_case c ON c.id = a.test_case_id
                WHERE i.evaluation_id = ? AND i.position > ? ORDER BY i.position LIMIT '.$this->batchSize,
                [$evaluationId, $position],
            );
            $scores = [];
            foreach ($items as $item) {
                foreach ($criteria as $criterion) {
                    $result = Checks::run($criterion['type'], $criterion['params'], (string) $item['output'], null === $item['reference'] ? null : (string) $item['reference']);
                    $scores[] = [(string) $item['id'], $criterion['id'], \is_bool($result) ? (int) $result : null, \is_string($result) ? $result : null];
                }
                $position = (int) $item['position'];
            }
            $processed += \count($items);
            $this->connection->transactional(function () use ($scores, $processed, $jobId): void {
                $this->scores->saveAutomatic($scores);
                $this->connection->executeStatement('UPDATE check_job SET processed = ? WHERE id = ?', [$processed, $jobId]);
            });
        } while (\count($items) === $this->batchSize);

        return $processed;
    }
}
