<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use App\Evaluations\Repository\ScoreRepository;
use App\Users\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One score per item and criterion: a unique index, so a save is an upsert in SQL (ScoreRepository::saveManual). A manual
 * score is 1–5 or 1/0, by a person; an automatic one comes from a check (slice 10), with a null value and an error
 * code when the check could not run.
 */
#[ORM\Entity(repositoryClass: ScoreRepository::class)]
#[ORM\Table(name: 'score')]
#[ORM\UniqueConstraint(name: 'score_item_criterion_key', columns: ['evaluation_item_id', 'criterion_id'])]
#[ORM\Index(name: 'score_criterion_idx', columns: ['criterion_id'])]
class Score
{
    public const MANUAL = 'manual';
    public const AUTOMATIC = 'automatic';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EvaluationItem $evaluationItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Criterion $criterion;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $value;

    /** "scored", or "check_error" when an automatic check could not run */
    #[ORM\Column(length: 20)]
    private string $outcome;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $errorCode;

    /** self::MANUAL or self::AUTOMATIC */
    #[ORM\Column(length: 10)]
    private string $source;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $scoredBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EvaluationItem $evaluationItem,
        Criterion $criterion,
        ?int $value,
        string $source,
        ?User $scoredBy,
        string $outcome = 'scored',
        ?string $errorCode = null,
    ) {
        $this->id = Uuid::v7();
        $this->evaluationItem = $evaluationItem;
        $this->criterion = $criterion;
        $this->value = $value;
        $this->source = $source;
        $this->scoredBy = $scoredBy;
        $this->outcome = $outcome;
        $this->errorCode = $errorCode;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvaluationItem(): EvaluationItem
    {
        return $this->evaluationItem;
    }

    public function getCriterion(): Criterion
    {
        return $this->criterion;
    }

    public function getValue(): ?int
    {
        return $this->value;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getScoredBy(): ?User
    {
        return $this->scoredBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
