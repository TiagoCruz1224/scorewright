<?php

declare(strict_types=1);

namespace App\Datasets\Repository;

use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every finder is scoped to a project (architecture §6). Runs carry their metadata only; answers are elsewhere.
 *
 * @extends ServiceEntityRepository<Run>
 */
final class RunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    public function findInProject(Uuid $projectId, Uuid $runId): ?Run
    {
        return $this->findOneBy(['project' => $projectId, 'id' => $runId]);
    }

    public function labelIsTaken(DatasetVersion $version, string $label): bool
    {
        return null !== $this->findOneBy(['datasetVersion' => $version, 'label' => trim($label)]);
    }

    /**
     * Newest first, with their dataset versions and datasets; only the runs of one version when $versionId is given.
     *
     * @return array{items: list<Run>, total: int}
     */
    public function findPageInProject(Uuid $projectId, ?Uuid $versionId, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('r')
            ->addSelect('v', 'd')
            ->join('r.datasetVersion', 'v')
            ->join('v.dataset', 'd')
            ->where('r.project = :project')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        if (null !== $versionId) {
            $query->andWhere('r.datasetVersion = :version')->setParameter('version', $versionId, UuidType::NAME);
        }
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }
}
