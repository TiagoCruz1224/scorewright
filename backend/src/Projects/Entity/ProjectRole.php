<?php

declare(strict_types=1);

namespace App\Projects\Entity;

enum ProjectRole: string
{
    case Owner = 'owner';
    case Evaluator = 'evaluator';
    case Viewer = 'viewer';

    /** For validation constraints, which need a constant expression. */
    public const VALUES = ['owner', 'evaluator', 'viewer'];
}
