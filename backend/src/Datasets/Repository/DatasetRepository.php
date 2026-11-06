<?php

declare(strict_types=1);

namespace App\Datasets\Repository;

use App\Datasets\Entity\Dataset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every finder is scoped to a project (architecture §6).
 *
 * @extends ServiceEntityRepository<Dataset>
 */
final class DatasetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dataset::class);
    }

    public function findInProject(Uuid $projectId, Uuid $datasetId): ?Dataset
    {
        return $this->findOneBy(['project' => $projectId, 'id' => $datasetId]);
    }

    /** Ignoring case, as the unique index on lower(name). */
    public function nameIsTaken(Uuid $projectId, string $name): bool
    {
        return [] !== $this->createQueryBuilder('d')
            ->select('d.id')
            ->where('d.project = :project AND LOWER(d.name) = LOWER(:name)')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('name', trim($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: list<Dataset>, total: int}
     */
    public function findPageInProject(Uuid $projectId, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('d')
            ->where('d.project = :project')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->orderBy('d.name')
            ->addOrderBy('d.id')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }
}
