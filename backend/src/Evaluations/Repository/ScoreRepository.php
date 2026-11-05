<?php

declare(strict_types=1);

namespace App\Evaluations\Repository;

use App\Evaluations\Entity\Criterion;
use App\Evaluations\Entity\EvaluationItem;
use App\Evaluations\Entity\Score;
use App\Users\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Score>
 */
final class ScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Score::class);
    }

    /**
     * Saves a person's score: an upsert on (item, criterion), so sending it twice is harmless and, between two
     * evaluators, the last save wins (architecture §5). Who and when are recorded.
     */
    public function saveManual(EvaluationItem $item, Criterion $criterion, int $value, User $by): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO score (id, evaluation_item_id, criterion_id, value, outcome, error_code, source, scored_by_id, updated_at)
            VALUES (?, ?, ?, ?, 'scored', NULL, 'manual', ?, NOW())
            ON CONFLICT (evaluation_item_id, criterion_id) DO UPDATE SET value = EXCLUDED.value, outcome = 'scored',
                error_code = NULL, source = 'manual', scored_by_id = EXCLUDED.scored_by_id, updated_at = EXCLUDED.updated_at",
            [Uuid::v7()->toRfc4122(), $item->getId()->toRfc4122(), $criterion->getId()->toRfc4122(), $value, $by->getId()->toRfc4122()],
        );
    }

    /**
     * Saves the results of automatic checks: upserts on (item, criterion), so a re-run gives the same rows. A value of
     * null with an error code is a check that could not run (outcome check_error). No one scored them: scored_by is null.
     *
     * @param list<array{string, string, int|null, string|null}> $scores [item id, criterion id, value, error code]
     */
    public function saveAutomatic(array $scores): void
    {
        // 6 parameters a row: 1,000 rows stay far below PostgreSQL's 65,535.
        foreach (array_chunk($scores, 1000) as $chunk) {
            $this->getEntityManager()->getConnection()->executeStatement(
                'INSERT INTO score (id, evaluation_item_id, criterion_id, value, outcome, error_code, source, scored_by_id, updated_at) VALUES '
                .implode(', ', array_fill(0, \count($chunk), "(?, ?, ?, ?, ?, ?, 'automatic', NULL, NOW())"))
                .' ON CONFLICT (evaluation_item_id, criterion_id) DO UPDATE SET value = EXCLUDED.value, outcome = EXCLUDED.outcome,
                    error_code = EXCLUDED.error_code, source = EXCLUDED.source, scored_by_id = NULL, updated_at = EXCLUDED.updated_at',
                array_merge(...array_map(
                    static fn (array $score): array => [Uuid::v7()->toRfc4122(), $score[0], $score[1], $score[2], null === $score[3] ? 'scored' : 'check_error', $score[3]],
                    $chunk,
                )),
            );
        }
    }

    /**
     * @return list<Score> with who scored them
     */
    public function forItem(EvaluationItem $item): array
    {
        /** @var list<Score> $scores */
        $scores = $this->createQueryBuilder('s')
            ->addSelect('u')
            ->leftJoin('s.scoredBy', 'u')
            ->where('s.evaluationItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();

        return $scores;
    }
}
