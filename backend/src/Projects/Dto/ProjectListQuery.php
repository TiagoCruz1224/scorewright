<?php

declare(strict_types=1);

namespace App\Projects\Dto;

use App\Http\PageQuery;
use Symfony\Component\Validator\Constraints as Assert;

/** PageQuery plus ?archived=. */
final readonly class ProjectListQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: PageQuery::MAX_PAGE)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 25,

        /** true lists only the archived projects. */
        public bool $archived = false,
    ) {
    }
}
