<?php

declare(strict_types=1);

namespace App\Evaluations\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The body of PUT …/scores/{criterionId}: 1 to 5 on a scale, 1 (pass) or 0 (fail). The controller checks the range. */
final readonly class ScoreRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Give a value.')]
        public ?int $value = null,
    ) {
    }
}
