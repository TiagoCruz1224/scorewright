<?php

declare(strict_types=1);

namespace App\Datasets\Dto;

use App\Datasets\Import\CaseFileReader;
use App\Http\NoControlCharacters;
use App\Http\PageQuery;
use Symfony\Component\Validator\Constraints as Assert;

/** PageQuery plus ?tag= (one tag, exact) and ?q= (text in the input, reference or case key). */
final readonly class CaseListQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: PageQuery::MAX_PAGE)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 25,

        #[Assert\Length(max: CaseFileReader::MAX_TAG_LENGTH)]
        #[NoControlCharacters]
        public string $tag = '',

        #[Assert\Length(max: 200)]
        #[NoControlCharacters]
        public string $q = '',
    ) {
    }
}
