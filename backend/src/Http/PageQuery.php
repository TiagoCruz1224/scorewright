<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\Constraints as Assert;

/** ?page= and ?perPage= of a list (architecture §5: default 25, at most 100). */
final readonly class PageQuery
{
    /** Far beyond the MVP sizes (5,000 cases even at one per page), and keeps the row offset a small integer. */
    public const MAX_PAGE = 10_000;

    public function __construct(
        #[Assert\Range(min: 1, max: self::MAX_PAGE)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 25,
    ) {
    }
}
