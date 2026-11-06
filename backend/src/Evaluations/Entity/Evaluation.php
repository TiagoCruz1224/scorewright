<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use App\Datasets\Entity\DatasetVersion;
use App\Evaluations\Repository\EvaluationRepository;
use App\Projects\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A rubric applied to one or more runs of one dataset version (architecture §4). Its items, one per answer, are what
 * evaluators score (ADR 0005). The name is unique in the project, ignoring case (an index on lower(name)). Closing is final (§12, decision 1).
 *
 * The dataset version, the runs and the rubric are not deleted while an evaluation uses them: the API answers 409
 * in_use, and the foreign keys refuse it too.
 */
#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\Table(name: 'evaluation')]
class Evaluation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 10, enumType: EvaluationStatus::class)]
    private EvaluationStatus $status = EvaluationStatus::Open;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private DatasetVersion $datasetVersion,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Rubric $rubric,

        string $name,

        #[ORM\Column]
        private bool $blind,
    ) {
        $this->id = Uuid::v7();
        $this->name = trim($name);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getDatasetVersion(): DatasetVersion
    {
        return $this->datasetVersion;
    }

    public function getRubric(): Rubric
    {
        return $this->rubric;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isBlind(): bool
    {
        return $this->blind;
    }

    public function getStatus(): EvaluationStatus
    {
        return $this->status;
    }

    public function isClosed(): bool
    {
        return EvaluationStatus::Closed === $this->status;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function close(): void
    {
        if ($this->isClosed()) {
            throw new \LogicException('A closed evaluation stays closed.');
        }
        $this->status = EvaluationStatus::Closed;
        $this->closedAt = new \DateTimeImmutable();
    }
}
