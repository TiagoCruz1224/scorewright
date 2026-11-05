<?php

declare(strict_types=1);

namespace App\Datasets\Entity;

use App\Datasets\Repository\RunRepository;
use App\Projects\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The answers of one model or system configuration to the cases of one dataset version, imported from a file and never
 * updated (architecture §4). A run may leave cases unanswered (§12, decision 2): its coverage is answerCount out of the
 * version's caseCount. The file is not kept, only its name and SHA-256, to show where the answers came from.
 */
#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Table(name: 'run')]
#[ORM\UniqueConstraint(name: 'run_version_label_key', columns: ['dataset_version_id', 'label'])]
class Run
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DatasetVersion $datasetVersion,

        #[ORM\Column(length: 100)]
        private string $label,

        #[ORM\Column(type: Types::TEXT)]
        private string $modelDescription,

        #[ORM\Column]
        private int $answerCount,

        #[ORM\Column(length: 255)]
        private string $sourceFilename,

        #[ORM\Column(length: 64)]
        private string $sha256,
    ) {
        $this->id = Uuid::v7();
        $this->label = trim($label);
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getModelDescription(): string
    {
        return $this->modelDescription;
    }

    public function getAnswerCount(): int
    {
        return $this->answerCount;
    }

    public function getSourceFilename(): string
    {
        return $this->sourceFilename;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
