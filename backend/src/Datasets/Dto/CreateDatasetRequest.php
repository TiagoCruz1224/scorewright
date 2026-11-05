<?php

declare(strict_types=1);

namespace App\Datasets\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

/** The "name" field of the multipart request that creates a dataset. */
final readonly class CreateDatasetRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        #[NoControlCharacters]
        public string $name = '',
    ) {
    }
}
