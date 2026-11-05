<?php

declare(strict_types=1);

namespace App\Datasets\Entity;

use App\Datasets\Repository\DatasetVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One import of a dataset file. Never updated (architecture §4). The file itself is not kept: only its SHA-256 and
 * its original name (ADR 0004).
 */
#[ORM\Entity(repositoryClass: DatasetVersionRepository::class)]
#[ORM\Table(name: 'dataset_version')]
#[ORM\UniqueConstraint(name: 'dataset_version_dataset_number_key', columns: ['dataset_id', 'number'])]
class DatasetVersion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Dataset $dataset,

        #[ORM\Column]
        private int $number,

        #[ORM\Column]
        private int $caseCount,

        #[ORM\Column(length: 255)]
        private string $sourceFilename,

        #[ORM\Column(length: 64)]
        private string $sha256,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDataset(): Dataset
    {
        return $this->dataset;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getCaseCount(): int
    {
        return $this->caseCount;
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
