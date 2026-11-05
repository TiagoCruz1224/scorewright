<?php

declare(strict_types=1);

namespace App\Evaluations\Repository;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\Run;
use App\Evaluations\Entity\CheckJob;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Entity\Rubric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Every finder is scoped to a project (architecture §6).
 *
 * @extends ServiceEntityRepository<Evaluation>
 */
final class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    public function findInProject(Uuid $projectId, Uuid $evaluationId): ?Evaluation
    {
        return $this->findOneBy(['project' => $projectId, 'id' => $evaluationId]);
    }

    public function nameIsTaken(Uuid $projectId, string $name): bool
    {
        return null !== $this->findOneBy(['project' => $projectId, 'name' => trim($name)]);
    }

    /**
     * Newest first, with their rubrics, dataset versions and datasets.
     *
     * @return array{items: list<Evaluation>, total: int}
     */
    public function findPageInProject(Uuid $projectId, int $page, int $perPage): array
    {
        $query = $this->createQueryBuilder('e')
            ->addSelect('r', 'v', 'd')
            ->join('e.rubric', 'r')
            ->join('e.datasetVersion', 'v')
            ->join('v.dataset', 'd')
            ->where('e.project = :project')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }

    /**
     * Manual scores only (architecture §5); automatic checks report through the check job.
     * - progress: scores given, of items × manual criteria;
     * - answers: the items with every manual score, of all the items ("296 of 424 answers scored"). Without manual
     *   criteria every answer has them all.
     * By evaluation id.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return array<string, array{progress: array{scored: int, total: int}, answers: array{scored: int, total: int}}>
     */
    public function progress(array $evaluations): array
    {
        if ([] === $evaluations) {
            return [];
        }
        // The scores of the manual criteria only, through score (criterion_id): an evaluation at the MVP limits also has
        // up to 25,000 × 30 automatic ones, which the counts must not read (about 10 ms instead of 850).
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT e.id, items.n AS items, manual.n AS manual, scored.n AS scored,
                CASE WHEN manual.n = 0 THEN items.n ELSE scored.answers END AS answers_scored
            FROM evaluation e
            CROSS JOIN LATERAL (SELECT COUNT(*) AS n FROM evaluation_item i WHERE i.evaluation_id = e.id) items
            CROSS JOIN LATERAL (SELECT COUNT(*) AS n FROM criterion c WHERE c.rubric_id = e.rubric_id AND c.kind <> 'automatic') manual
            CROSS JOIN LATERAL (
                SELECT COALESCE(SUM(per_item.n), 0) AS n, COUNT(*) FILTER (WHERE per_item.n >= manual.n) AS answers
                FROM (
                    SELECT COUNT(*) AS n FROM criterion c
                    JOIN score s ON s.criterion_id = c.id
                    JOIN evaluation_item i ON i.id = s.evaluation_item_id
                    WHERE c.rubric_id = e.rubric_id AND c.kind <> 'automatic' AND i.evaluation_id = e.id
                    GROUP BY s.evaluation_item_id
                ) per_item
            ) scored
            WHERE e.id IN (?)",
            [array_map(static fn (Evaluation $evaluation): string => $evaluation->getId()->toRfc4122(), $evaluations)],
            [ArrayParameterType::STRING],
        );
        $progress = [];
        foreach ($rows as $row) {
            $progress[(string) $row['id']] = [
                'progress' => ['scored' => (int) $row['scored'], 'total' => (int) $row['items'] * (int) $row['manual']],
                'answers' => ['scored' => (int) $row['answers_scored'], 'total' => (int) $row['items']],
            ];
        }

        return $progress;
    }

    /**
     * The scores of each criterion in the evaluation, manual or automatic, check errors included ("Progress by
     * criterion").
     *
     * @return array<string, int> by criterion id
     */
    public function scoresByCriterion(Evaluation $evaluation): array
    {
        /** @var array<string, int|string> $counts */
        $counts = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT s.criterion_id, COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ? GROUP BY s.criterion_id',
            [$evaluation->getId()->toRfc4122()],
        );

        return array_map(intval(...), $counts);
    }

    /**
     * @param list<Evaluation> $evaluations
     *
     * @return array<string, list<EvaluationRun>> by evaluation id, each by letter
     */
    public function runs(array $evaluations): array
    {
        $runs = [];
        foreach ($this->getEntityManager()->getRepository(EvaluationRun::class)->findBy(['evaluation' => $evaluations], ['letter' => 'ASC']) as $entry) {
            $runs[$entry->getEvaluation()->getId()->toRfc4122()][] = $entry;
        }

        return $runs;
    }

    /** created_at keeps whole seconds: within one, the time-ordered v7 id tells the newer job. */
    public function latestCheckJob(Evaluation $evaluation): ?CheckJob
    {
        return $this->getEntityManager()->getRepository(CheckJob::class)->findOneBy(['evaluation' => $evaluation], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * The evaluations that keep a rubric, a run or a dataset from being deleted (architecture §4, "Deleting").
     *
     * @return list<array{id: string, name: string}> by name
     */
    public function using(Rubric|Run|Dataset $resource): array
    {
        $query = $this->createQueryBuilder('e')->select('e.id', 'e.name')->orderBy('e.name');
        match (true) {
            $resource instanceof Rubric => $query->where('e.rubric = :resource'),
            $resource instanceof Run => $query->where('e.id IN (SELECT IDENTITY(er.evaluation) FROM '.EvaluationRun::class.' er WHERE er.run = :resource)'),
            $resource instanceof Dataset => $query->join('e.datasetVersion', 'v')->where('v.dataset = :resource'),
        };
        $query->setParameter('resource', $resource->getId(), UuidType::NAME);

        return array_values(array_map(
            static fn (array $row): array => ['id' => $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : (string) $row['id'], 'name' => (string) $row['name']],
            $query->getQuery()->getArrayResult(),
        ));
    }
}
