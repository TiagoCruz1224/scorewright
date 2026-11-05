<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One criterion of a rubric, at its position (from 0). Manual criteria (scale 1–5, pass/fail) have guidance for the
 * evaluators; automatic ones have a check type and its parameters (architecture §4, §7). The weight is optional: the
 * results count an empty weight as 1 (§12, decision 3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'criterion')]
#[ORM\Index(name: 'criterion_rubric_position_idx', columns: ['rubric_id', 'position'])]
class Criterion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: CriterionKind::class)]
    private CriterionKind $kind;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $guidance;

    #[ORM\Column(nullable: true)]
    private ?float $weight;

    #[ORM\Column(length: 20, nullable: true, enumType: CheckType::class)]
    private ?CheckType $checkType;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $checkParams;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'criteria')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Rubric $rubric,

        #[ORM\Column]
        private int $position,

        CriterionData $data,
    ) {
        $this->id = Uuid::v7();
        $this->kind = $data->kind;
        $this->name = $data->name;
        $this->guidance = $data->guidance;
        $this->weight = $data->weight;
        $this->checkType = $data->checkType;
        $this->checkParams = $data->checkParams;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRubric(): Rubric
    {
        return $this->rubric;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getData(): CriterionData
    {
        return new CriterionData($this->kind, $this->name, $this->guidance, $this->weight, $this->checkType, $this->checkParams);
    }
}
