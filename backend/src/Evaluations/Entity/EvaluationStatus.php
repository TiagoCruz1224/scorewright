<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

/** Closing is final: a closed evaluation is never reopened (architecture §12, decision 1). */
enum EvaluationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
