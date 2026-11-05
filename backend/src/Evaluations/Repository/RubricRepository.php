<?php

declare(strict_types=1);

namespace App\Evaluations\Repository;

use App\Evaluations\Entity\Rubric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every finder is scoped to a project (architecture §6).
 *
 * @extends ServiceEntityRepository<Rubric>
 */
final class RubricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rubric::class);
    }

    /** With LockMode::PESSIMISTIC_WRITE (in a transaction) the row stays locked until the transaction ends. */
    public function findInProject(Uuid $projectId, Uuid $rubricId, ?LockMode $lockMode = null): ?Rubric
    {
        $query = $this->createQueryBuilder('r')
            ->where('r.project = :project AND r.id = :id')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('id', $rubricId, UuidType::NAME)
            ->getQuery();
        if (null !== $lockMode) {
            $query->setLockMode($lockMode);
        }
        $rubric = $query->getOneOrNullResult();

        return $rubric instanceof Rubric ? $rubric : null;
    }

    /** Whether another rubric of the project has this name. */
    public function nameIsTaken(Uuid $projectId, string $name, ?Rubric $except = null): bool
    {
        $rubric = $this->findOneBy(['project' => $projectId, 'name' => trim($name)]);

        return null !== $rubric && $rubric !== $except;
    }

    /**
     * By name, with their criteria (to count them).
     *
     * @return array{items: list<Rubric>, total: int}
     */
    public function findPageInProject(Uuid $projectId, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('r')
            ->addSelect('c')
            ->leftJoin('r.criteria', 'c')
            ->where('r.project = :project')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->orderBy('r.name')
            ->addOrderBy('r.id')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $paginator = new Paginator($query, fetchJoinCollection: true);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }
}
