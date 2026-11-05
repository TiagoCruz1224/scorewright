<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A run of the automatic checks over an evaluation's items (architecture §7). At most one is queued or running per
 * evaluation: a partial unique index. Slice 9 creates it queued; slice 10 dispatches and runs it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'check_job')]
#[ORM\UniqueConstraint(name: 'check_job_active_key', columns: ['evaluation_id'], options: ['where' => self::ACTIVE])]
class CheckJob
{
    /** As PostgreSQL returns it, so the mapping and the database compare equal (status IN ('queued', 'running')). */
    private const ACTIVE = "((status)::text = ANY ((ARRAY['queued'::character varying, 'running'::character varying])::text[]))";

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 10, enumType: CheckJobStatus::class)]
    private CheckJobStatus $status = CheckJobStatus::Queued;

    #[ORM\Column]
    private int $processed = 0;

    /** Set by slice 10 when a job cannot finish. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $errorCode = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Evaluation $evaluation,

        /** The items to check. */
        #[ORM\Column]
        private int $total,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvaluation(): Evaluation
    {
        return $this->evaluation;
    }

    public function getStatus(): CheckJobStatus
    {
        return $this->status;
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
