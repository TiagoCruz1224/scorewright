<?php

declare(strict_types=1);

namespace App\Datasets\Repository;

use App\Datasets\Entity\Answer;
use App\Datasets\Entity\Run;
use App\Datasets\Import\ImportedAnswer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Answers are written in batches, like test cases (ADR 0004). There is deliberately no finder that lists a run's
 * answers yet (architecture §5): scoring and results add their own, scoped to an evaluation.
 *
 * @extends ServiceEntityRepository<Answer>
 */
final class AnswerRepository extends ServiceEntityRepository
{
    /** 4 parameters per row. */
    private const BATCH_SIZE = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Answer::class);
    }

    /**
     * Run inside the import transaction.
     *
     * @param list<ImportedAnswer> $answers
     */
    public function insertBatch(Run $run, array $answers): void
    {
        $connection = $this->getEntityManager()->getConnection();
        foreach (array_chunk($answers, self::BATCH_SIZE) as $chunk) {
            $params = [];
            foreach ($chunk as $answer) {
                array_push($params, Uuid::v7()->toRfc4122(), $run->getId()->toRfc4122(), $answer->testCaseId, $answer->output);
            }
            $connection->executeStatement(
                'INSERT INTO answer (id, run_id, test_case_id, output) VALUES '.implode(', ', array_fill(0, \count($chunk), '(?, ?, ?, ?)')),
                $params,
            );
        }
    }
}
