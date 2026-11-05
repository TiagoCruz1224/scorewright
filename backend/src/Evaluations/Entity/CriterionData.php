<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

/**
 * What a criterion says, without its rubric and position: what a rubric body gives for each criterion
 * (CriterionRules::parse) and what a duplicate copies. checkType and checkParams are set for automatic criteria only.
 */
final readonly class CriterionData
{
    /**
     * @param array<string, mixed>|null $checkParams
     */
    public function __construct(
        public CriterionKind $kind,
        public string $name,
        public string $guidance = '',
        public ?float $weight = null,
        public ?CheckType $checkType = null,
        public ?array $checkParams = null,
    ) {
    }
}
