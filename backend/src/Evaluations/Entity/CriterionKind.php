<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

/** Manual criteria are scored by people, with guidance; automatic ones by a deterministic check (architecture §7). */
enum CriterionKind: string
{
    case Scale1To5 = 'scale_1_5';
    case PassFail = 'pass_fail';
    case Automatic = 'automatic';
}
