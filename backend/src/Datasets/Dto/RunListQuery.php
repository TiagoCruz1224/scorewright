<?php

declare(strict_types=1);

namespace App\Datasets\Dto;

use App\Http\PageQuery;
use Symfony\Component\Validator\Constraints as Assert;

/** PageQuery plus ?datasetVersion=, to list the runs of one version. */
final readonly class RunListQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: PageQuery::MAX_PAGE)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 25,

        #[Assert\Uuid]
        public string $datasetVersion = '',
    ) {
    }
}
