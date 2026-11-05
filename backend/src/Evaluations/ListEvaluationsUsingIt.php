<?php

declare(strict_types=1);

namespace App\Evaluations;

use App\Datasets\DeletionRequested;
use App\Evaluations\Repository\EvaluationRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Tells the Datasets module which evaluations keep a dataset or a run from being deleted. */
#[AsEventListener]
final readonly class ListEvaluationsUsingIt
{
    public function __construct(
        private EvaluationRepository $evaluations,
    ) {
    }

    public function __invoke(DeletionRequested $event): void
    {
        $event->usedBy = $this->evaluations->using($event->resource);
    }
}
