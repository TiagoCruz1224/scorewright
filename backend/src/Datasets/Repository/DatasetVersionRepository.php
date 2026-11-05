<?php

declare(strict_types=1);

namespace App\Datasets\Repository;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every finder is scoped to a project or to a dataset already found in one (architecture §6).
 *
 * @extends ServiceEntityRepository<DatasetVersion>
 */
final class DatasetVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetVersion::class);
    }

    public function findInProject(Uuid $projectId, Uuid $versionId): ?DatasetVersion
    {
        $version = $this->createQueryBuilder('v')
            ->join('v.dataset', 'd')
            ->where('v.id = :version')
            ->andWhere('d.project = :project')
            ->setParameter('version', $versionId, UuidType::NAME)
            ->setParameter('project', $projectId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $version instanceof DatasetVersion ? $version : null;
    }

    /**
     * Newest first.
     *
     * @return list<DatasetVersion>
     */
    public function findByDataset(Dataset $dataset): array
    {
        return $this->findBy(['dataset' => $dataset], ['number' => 'DESC']);
    }

    /**
     * The latest version of each dataset, keyed by dataset id.
     *
     * @param list<Dataset> $datasets
     *
     * @return array<string, DatasetVersion>
     */
    public function findLatestOf(array $datasets): array
    {
        if ([] === $datasets) {
            return [];
        }

        /** @var list<DatasetVersion> $versions */
        $versions = $this->createQueryBuilder('v')
            ->where('v.dataset IN (:datasets)')
            ->andWhere('v.number = (SELECT MAX(v2.number) FROM '.DatasetVersion::class.' v2 WHERE v2.dataset = v.dataset)')
            ->setParameter('datasets', array_map(static fn (Dataset $dataset): string => $dataset->getId()->toRfc4122(), $datasets))
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($versions as $version) {
            $latest[$version->getDataset()->getId()->toRfc4122()] = $version;
        }

        return $latest;
    }

    /** Call with the dataset row locked, so two imports cannot take the same number. */
    public function nextNumber(Dataset $dataset): int
    {
        return 1 + (int) $this->createQueryBuilder('v')
            ->select('MAX(v.number)')
            ->where('v.dataset = :dataset')
            ->setParameter('dataset', $dataset->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
