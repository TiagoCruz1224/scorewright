<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

enum CheckJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
