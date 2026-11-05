<?php

declare(strict_types=1);

namespace App\Users\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public string $email = '',

        // Length only: no composition rules and no third-party breach lookup (architecture §6).
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 128)]
        public string $password = '',

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        #[NoControlCharacters]
        public string $displayName = '',
    ) {
    }
}
