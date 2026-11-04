<?php

declare(strict_types=1);

namespace App\Projects\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** PATCH: only the fields that are present change. */
final readonly class UpdateProjectRequest
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        public ?string $name = null,

        #[Assert\Length(max: 2000)]
        public ?string $description = null,
    ) {
    }
}
