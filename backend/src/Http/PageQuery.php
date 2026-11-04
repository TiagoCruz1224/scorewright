<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\Constraints as Assert;

/** ?page= and ?perPage= of a list (architecture §5: default 25, at most 100). */
final readonly class PageQuery
{
    public function __construct(
        #[Assert\Positive]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 25,
    ) {
    }
}
