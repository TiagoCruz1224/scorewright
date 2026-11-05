<?php

declare(strict_types=1);

namespace App\Evaluations\Repository;

use App\Datasets\Entity\Run;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Random\Randomizer;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Items are scoped to their evaluation, which is scoped to its project (architecture §6).
 *
 * @extends ServiceEntityRepository<EvaluationItem>
 */
final class EvaluationItemRepository extends ServiceEntityRepository
{
    /** Rows per INSERT: 4 parameters each, far below PostgreSQL's 65,535. */
    private const BATCH = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationItem::class);
    }

    /**
     * One item per answer of the runs (ADR 0005), in batches; a run that leaves a case unanswered has no item for it.
     * - Each item gets a random v4 UUID, which reveals neither its run nor when it was made.
     * - Positions group the items by case, in the order of the cases. Within a case, the runs follow their letters, or
     *   in blind mode a random order: Randomizer's default engine is the CSPRNG behind random_int().
     *
     * @param list<Run> $runs in the order of their letters
     *
     * @return int the number of items
     */
    public function createFor(Evaluation $evaluation, array $runs): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $runIds = array_map(static fn (Run $run): string => $run->getId()->toRfc4122(), $runs);
        $letters = array_flip($runIds);
        $randomizer = new Randomizer();

        $byCase = [];
        foreach ($connection->iterateAssociative(
            'SELECT a.id, a.run_id, c.position FROM answer a JOIN test_case c ON c.id = a.test_case_id WHERE a.run_id IN (?) ORDER BY c.position',
            [$runIds],
            [ArrayParameterType::STRING],
        ) as $answer) {
            $byCase[(int) $answer['position']][] = [(string) $answer['id'], $letters[(string) $answer['run_id']]];
        }

        $evaluationId = $evaluation->getId()->toRfc4122();
        $rows = [];
        foreach ($byCase as $answers) {
            usort($answers, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            if ($evaluation->isBlind()) {
                $answers = $randomizer->shuffleArray($answers);
            }
            foreach ($answers as [$answerId]) {
                $rows[] = [Uuid::v4()->toRfc4122(), $evaluationId, $answerId, \count($rows)];
            }
        }

        foreach (array_chunk($rows, self::BATCH) as $batch) {
            $connection->executeStatement(
                'INSERT INTO evaluation_item (id, evaluation_id, answer_id, position) VALUES '.implode(', ', array_fill(0, \count($batch), '(?, ?, ?, ?)')),
                array_merge(...$batch),
            );
        }

        return \count($rows);
    }

    /** With its answer and case, which every item response shows. */
    public function findInEvaluation(Evaluation $evaluation, Uuid $itemId): ?EvaluationItem
    {
        $item = $this->createQueryBuilder('i')
            ->addSelect('a', 'c')
            ->join('i.answer', 'a')
            ->join('a.testCase', 'c')
            ->where('i.evaluation = :evaluation AND i.id = :id')
            ->setParameter('evaluation', $evaluation->getId(), UuidType::NAME)
            ->setParameter('id', $itemId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $item instanceof EvaluationItem ? $item : null;
    }

    /**
     * The first item by position that lacks a manual score for some manual criterion (architecture §5). It is per
     * evaluation, not per evaluator: there is no locking between evaluators in the MVP.
     */
    public function nextToScore(Evaluation $evaluation, int $manualCriteria): ?EvaluationItem
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT i.id FROM evaluation_item i
            WHERE i.evaluation_id = ?
                AND (SELECT COUNT(*) FROM score s WHERE s.evaluation_item_id = i.id AND s.source = 'manual') < ?
            ORDER BY i.position LIMIT 1",
            [$evaluation->getId()->toRfc4122(), $manualCriteria],
        );

        return \is_string($id) ? $this->findInEvaluation($evaluation, Uuid::fromString($id)) : null;
    }
}
