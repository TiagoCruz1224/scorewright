<?php

declare(strict_types=1);

namespace App\Datasets\Entity;

use App\Datasets\Repository\TestCaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One case of a dataset version. Imports insert cases in batches through DBAL (TestCaseRepository::insertBatch) and
 * the cases list reads them through DBAL; this mapping keeps the table in the schema and is used by tests.
 *
 * - position: the case's order in the file (1-based), so lists keep the file order.
 * - tags: a JSON array of strings with a GIN index (jsonb_path_ops, created in the migration), filtered with @>.
 * - metadata: a JSON object, {} when the file has none.
 */
#[ORM\Entity(repositoryClass: TestCaseRepository::class)]
#[ORM\Table(name: 'test_case')]
#[ORM\UniqueConstraint(name: 'test_case_version_key_key', columns: ['dataset_version_id', 'case_key'])]
#[ORM\Index(name: 'test_case_version_position_idx', columns: ['dataset_version_id', 'position'])]
#[ORM\Index(name: 'test_case_tags_idx', columns: ['tags'])]
class TestCase
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * @param list<string> $tags
     * @param array<mixed> $metadata
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private DatasetVersion $datasetVersion,

        #[ORM\Column]
        private int $position,

        #[ORM\Column(length: 100)]
        private string $caseKey,

        #[ORM\Column(type: Types::TEXT)]
        private string $input,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $reference = null,

        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $tags = [],

        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $metadata = [],
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDatasetVersion(): DatasetVersion
    {
        return $this->datasetVersion;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCaseKey(): string
    {
        return $this->caseKey;
    }

    public function getInput(): string
    {
        return $this->input;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @return array<mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
