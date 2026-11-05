<?php

declare(strict_types=1);

namespace App\Evaluations\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

/** The optional body of POST rubrics/{id}/duplicate: the copy's name, "<name> (copy)" when it is not given. */
final readonly class DuplicateRubricRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Give the copy a name.', allowNull: true, normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        #[NoControlCharacters]
        public ?string $name = null,
    ) {
    }
}
