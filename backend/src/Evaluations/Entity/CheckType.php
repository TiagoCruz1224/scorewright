<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

/** The deterministic check of an automatic criterion; each gives pass or fail (architecture §7). */
enum CheckType: string
{
    case ExactMatch = 'exact_match';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Regex = 'regex';
    case Length = 'length';
    case ValidJson = 'valid_json';
}
