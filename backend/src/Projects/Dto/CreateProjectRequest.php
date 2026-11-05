<?php

declare(strict_types=1);

namespace App\Projects\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateProjectRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        #[NoControlCharacters]
        public string $name = '',

        #[Assert\Length(max: 2000)]
        #[NoControlCharacters(multiline: true)]
        public string $description = '',
    ) {
    }
}
