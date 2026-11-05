<?php

declare(strict_types=1);

namespace App\Datasets\Entity;

use App\Datasets\Repository\DatasetRepository;
use App\Projects\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A named collection of test cases. Every import adds an immutable DatasetVersion. */
#[ORM\Entity(repositoryClass: DatasetRepository::class)]
#[ORM\Table(name: 'dataset')]
#[ORM\UniqueConstraint(name: 'dataset_project_name_key', columns: ['project_id', 'name'])]
class Dataset
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

        #[ORM\Column(length: 200)]
        private string $name,
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
