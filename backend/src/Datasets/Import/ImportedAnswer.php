<?php

declare(strict_types=1);

namespace App\Datasets\Import;

/** A valid answer read from a run file, for the case it answers. */
final readonly class ImportedAnswer
{
    public function __construct(
        public string $testCaseId,
        public string $output,
    ) {
    }
}
