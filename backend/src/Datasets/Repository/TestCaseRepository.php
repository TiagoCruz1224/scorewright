<?php

declare(strict_types=1);

namespace App\Datasets\Repository;

use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\TestCase;
use App\Datasets\Import\ImportedCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Cases are written and read with DBAL: an import inserts up to 5,000 rows in a few multi-row INSERTs (ADR 0004),
 * and the list filters on the jsonb tags. Every query is scoped to a version found through its project.
 *
 * @extends ServiceEntityRepository<TestCase>
 */
final class TestCaseRepository extends ServiceEntityRepository
{
    /** 8 parameters per row: 8,000 per statement, well below PostgreSQL's 65,535. */
    private const BATCH_SIZE = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TestCase::class);
    }

    /**
     * Run inside the import transaction; positions follow the file order.
     *
     * @param list<ImportedCase> $cases
     */
    public function insertBatch(DatasetVersion $version, array $cases): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (array_chunk($cases, self::BATCH_SIZE) as $chunkIndex => $chunk) {
            $params = [];
            foreach ($chunk as $index => $case) {
                array_push(
                    $params,
                    Uuid::v7()->toRfc4122(),
                    $version->getId()->toRfc4122(),
                    $chunkIndex * self::BATCH_SIZE + $index + 1,
                    $case->caseKey,
                    $case->input,
                    $case->reference,
                    json_encode($case->tags, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                    $case->metadata,
                );
            }
            $connection->executeStatement(
                'INSERT INTO test_case (id, dataset_version_id, position, case_key, input, reference, tags, metadata) VALUES '
                .implode(', ', array_fill(0, \count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')),
                $params,
            );
        }
    }

    /**
     * The version's case keys and their case ids, for a run import to find each answer's case.
     *
     * @return array<string, string> case key => test case id
     */
    public function idsByKey(DatasetVersion $version): array
    {
        /** @var array<string, string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT case_key, id FROM test_case WHERE dataset_version_id = ?',
            [$version->getId()->toRfc4122()],
        );

        return $ids;
    }

    /**
     * Cases in file order; $tag matches one tag exactly, $text is a case-insensitive substring of the input, the
     * reference or the case key.
     *
     * @return array{items: list<array{id: string, caseKey: string, input: string, reference: string|null, tags: mixed, metadata: mixed}>, total: int}
     */
    public function findPageInVersion(DatasetVersion $version, ?string $tag, ?string $text, int $page, int $perPage): array
    {
        $where = 'dataset_version_id = :version';
        $params = ['version' => $version->getId()->toRfc4122()];
        if (null !== $tag) {
            $where .= ' AND tags @> jsonb_build_array(CAST(:tag AS text))';
            $params['tag'] = $tag;
        }
        if (null !== $text) {
            $where .= ' AND (input ILIKE :text OR reference ILIKE :text OR case_key ILIKE :text)';
            $params['text'] = '%'.addcslashes($text, '%_\\').'%';
        }

        $connection = $this->getEntityManager()->getConnection();
        $rows = $connection->fetchAllAssociative(
            \sprintf('SELECT id, case_key, input, reference, tags, metadata FROM test_case WHERE %s ORDER BY position LIMIT %d OFFSET %d', $where, $perPage, ($page - 1) * $perPage),
            $params,
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'caseKey' => (string) $row['case_key'],
                'input' => (string) $row['input'],
                'reference' => null === $row['reference'] ? null : (string) $row['reference'],
                'tags' => json_decode((string) $row['tags'], true, flags: \JSON_THROW_ON_ERROR),
                // Decoded as objects, so that an empty metadata object stays {} in the response.
                'metadata' => json_decode((string) $row['metadata'], false, flags: \JSON_THROW_ON_ERROR),
            ], $rows),
            'total' => (int) $connection->fetchOne("SELECT COUNT(*) FROM test_case WHERE $where", $params),
        ];
    }
}
