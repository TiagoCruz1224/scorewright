<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use App\Evaluations\Repository\RubricRepository;
use App\Projects\Entity\Project;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An ordered list of criteria (architecture §4). The name is unique in the project. Once an evaluation uses the rubric
 * it is locked: it is never edited again, so existing scores keep their meaning, but it can be duplicated.
 */
#[ORM\Entity(repositoryClass: RubricRepository::class)]
#[ORM\Table(name: 'rubric')]
#[ORM\UniqueConstraint(name: 'rubric_project_name_key', columns: ['project_id', 'name'])]
class Rubric
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    /** @var Collection<int, Criterion> */
    #[ORM\OneToMany(targetEntity: Criterion::class, mappedBy: 'rubric', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $criteria;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,

        string $name,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Rubric $duplicatedFrom = null,
    ) {
        $this->id = Uuid::v7();
        $this->name = trim($name);
        $this->criteria = new ArrayCollection();
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

    public function rename(string $name): void
    {
        $this->name = trim($name);
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function isLocked(): bool
    {
        return null !== $this->lockedAt;
    }

    /** Slice 9 calls this in the transaction that creates the rubric's first evaluation. It stays locked. */
    public function lock(): void
    {
        $this->lockedAt ??= new \DateTimeImmutable();
    }

    public function getDuplicatedFrom(): ?Rubric
    {
        return $this->duplicatedFrom;
    }

    /**
     * @return list<Criterion> in order
     */
    public function getCriteria(): array
    {
        return array_values($this->criteria->toArray());
    }

    /**
     * Replaces every criterion; their order is the order of the list. Only an unlocked rubric is edited, and it has no
     * scores yet, so its criteria can be recreated.
     *
     * @param list<CriterionData> $criteria
     */
    public function replaceCriteria(array $criteria): void
    {
        if ($this->isLocked()) {
            throw new \LogicException('A locked rubric is never edited.');
        }
        $this->criteria->clear();
        foreach ($criteria as $position => $data) {
            $this->criteria->add(new Criterion($this, $position, $data));
        }
    }

    /** An unlocked copy, with copies of the criteria, that remembers where it came from. */
    public function duplicate(string $name): self
    {
        $copy = new self($this->project, $name, $this);
        $copy->replaceCriteria(array_map(static fn (Criterion $criterion): CriterionData => $criterion->getData(), $this->getCriteria()));

        return $copy;
    }
}
