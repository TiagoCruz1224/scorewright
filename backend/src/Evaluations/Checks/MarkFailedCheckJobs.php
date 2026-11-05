<?php

declare(strict_types=1);

namespace App\Evaluations\Checks;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * The state of a check job whose handler threw (architecture §7). It runs after Messenger's own retry listener, which
 * decides whether the message is tried again:
 * - another try is coming: the job is queued again, still active, so it cannot be closed or doubled meanwhile;
 * - that was the last try: the job has failed, with error_code unexpected_error (the exception is in the worker's log).
 */
#[AsEventListener]
final class MarkFailedCheckJobs
{
    public const UNEXPECTED_ERROR = 'unexpected_error';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RunAutomaticChecks) {
            return;
        }

        if ($event->willRetry()) {
            $this->connection->executeStatement("UPDATE check_job SET status = 'queued' WHERE id = ? AND status = 'running'", [$message->jobId]);
        } else {
            $this->connection->executeStatement(
                "UPDATE check_job SET status = 'failed', error_code = ? WHERE id = ? AND status IN ('queued', 'running')",
                [self::UNEXPECTED_ERROR, $message->jobId],
            );
        }
    }
}
