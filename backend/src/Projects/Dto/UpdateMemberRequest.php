<?php

declare(strict_types=1);

namespace App\Projects\Dto;

use App\Projects\Entity\ProjectRole;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateMemberRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ProjectRole::VALUES)]
        public string $role = '',
    ) {
    }
}
