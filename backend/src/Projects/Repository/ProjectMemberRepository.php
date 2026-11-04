<?php

declare(strict_types=1);

namespace App\Projects\Repository;

use App\Projects\Entity\ProjectMember;
use App\Projects\Entity\ProjectRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every finder is scoped to a project or to the signed-in user, so a forgotten check cannot reach another project's
 * rows (architecture §6).
 *
 * @extends ServiceEntityRepository<ProjectMember>
 */
final class ProjectMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMember::class);
    }

    public function findInProject(Uuid $projectId, Uuid $userId): ?ProjectMember
    {
        return $this->findOneBy(['project' => $projectId, 'user' => $userId]);
    }

    public function countOwners(Uuid $projectId): int
    {
        return $this->count(['project' => $projectId, 'role' => ProjectRole::Owner]);
    }

    /**
     * The user's memberships, with their projects, ordered by project name.
     *
     * @return array{items: list<ProjectMember>, total: int}
     */
    public function findPageForUser(Uuid $userId, bool $archived, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('m')
            ->join('m.project', 'p')
            ->where('m.user = :user')
            ->andWhere($archived ? 'p.archivedAt IS NOT NULL' : 'p.archivedAt IS NULL')
            ->setParameter('user', $userId, UuidType::NAME);
        $count = (clone $query)->select('COUNT(p.id)');

        return self::page($query->addSelect('p')->orderBy('p.name')->addOrderBy('p.id'), $count, $page, $perPage);
    }

    /**
     * @return array{items: list<ProjectMember>, total: int}
     */
    public function findPageInProject(Uuid $projectId, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('m')
            ->join('m.user', 'u')
            ->where('m.project = :project')
            ->setParameter('project', $projectId, UuidType::NAME);
        $count = (clone $query)->select('COUNT(u.id)');

        return self::page($query->addSelect('u')->orderBy('u.displayName')->addOrderBy('u.email'), $count, $page, $perPage);
    }

    /**
     * @return array{items: list<ProjectMember>, total: int}
     */
    private static function page(QueryBuilder $items, QueryBuilder $count, int $page, int $perPage): array
    {
        /** @var list<ProjectMember> $result */
        $result = $items->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return ['items' => $result, 'total' => (int) $count->getQuery()->getSingleScalarResult()];
    }
}
