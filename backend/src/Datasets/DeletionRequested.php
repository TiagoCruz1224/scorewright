<?php

declare(strict_types=1);

namespace App\Datasets;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\Run;

/**
 * Dispatched before a dataset or a run is deleted. Evaluations use them, but the Datasets module must not use the
 * Evaluations module (architecture §3), so the Evaluations module listens and lists the evaluations that use the
 * resource; when there are any, the delete answers 409 in_use (§4, "Deleting").
 */
final class DeletionRequested
{
    /** @var list<array{id: string, name: string}> */
    public array $usedBy = [];

    public function __construct(
        public readonly Dataset|Run $resource,
    ) {
    }
}
