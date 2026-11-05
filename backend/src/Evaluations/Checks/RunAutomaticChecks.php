<?php

declare(strict_types=1);

namespace App\Evaluations\Checks;

/** Runs the automatic checks of one check job (architecture §7), in the worker: routed to the async transport. */
final readonly class RunAutomaticChecks
{
    public function __construct(public string $jobId)
    {
    }
}
