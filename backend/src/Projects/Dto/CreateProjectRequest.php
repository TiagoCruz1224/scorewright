<?php

declare(strict_types=1);

namespace App\Projects\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateProjectRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        public string $name = '',

        #[Assert\Length(max: 2000)]
        public string $description = '',
    ) {
    }
}
