<?php

declare(strict_types=1);

namespace App\Evaluations\Security;

use App\Evaluations\Entity\Evaluation;
use App\Projects\ProjectAccess;

/** An evaluation as the caller sees it: the subject of EvaluationVoter::READ_RESULTS. */
final readonly class EvaluationAccess
{
    public function __construct(
        public ProjectAccess $projectAccess,
        public Evaluation $evaluation,
    ) {
    }
}
