<?php

declare(strict_types=1);

namespace App\Analytics\Command;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Evaluations\Checks\RunAutomaticChecks;
use App\Evaluations\Checks\RunAutomaticChecksHandler;
use App\Evaluations\Entity\CheckJob;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Repository\EvaluationItemRepository;
use App\Projects\Entity\Project;
use App\Projects\Entity\ProjectMember;
use App\Projects\Entity\ProjectRole;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A new project at the MVP limits (requirements §5, ADR 0007), to time the results: 5,000 cases with tags, 5 runs (the
 * last one leaves 4% of the cases unanswered), a rubric of 3 manual and 3 automatic criteria with weights, one blind
 * evaluation, every manual score given and the automatic checks run: about 150,000 scores. The same data each time
 * (a seeded random engine). Bulk inserts; dev and test only.
 */
#[AsCommand(name: 'app:seed-performance', description: 'Fills a new project with data at the MVP limits, to time the results (dev and test only)')]
final class SeedPerformanceCommand extends Command
{
    private const BATCH = 1000;
    private const TAGS = ['billing', 'refund', 'delivery', 'account', 'returns', 'prompt-injection', 'refusal', 'smalltalk'];
    private const RUNS = ['baseline-v1', 'tuned-v2', 'small-v1', 'large-v1', 'draft-v0'];
    private const SCORE_COLUMNS = ['id', 'evaluation_item_id', 'criterion_id', 'value', 'outcome', 'source', 'scored_by_id', 'updated_at'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly EvaluationItemRepository $items,
        private readonly RunAutomaticChecksHandler $checks,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'The email of an existing user, made the owner; by default a new user who cannot sign in')
            ->addOption('cases', null, InputOption::VALUE_REQUIRED, 'Cases in the dataset version', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            $io->error("app:seed-performance only runs in dev and test, not in \"$this->environment\": it writes about 200,000 rows.");

            return Command::FAILURE;
        }
        $cases = (int) $input->getOption('cases');
        if ($cases < 1 || $cases > 5000) {
            $io->error('Use from 1 to 5,000 cases, the MVP limit.');

            return Command::INVALID;
        }
        $owner = $this->owner($input->getOption('owner'));
        if (null === $owner) {
            $io->error('No user has this email. Register first, or leave --owner out.');

            return Command::FAILURE;
        }

        $started = hrtime(true);
        $random = new Randomizer(new Mt19937(20261105));
        $evaluation = $this->connection->transactional(fn (): Evaluation => $this->seed($owner, $cases, $random));
        $job = new CheckJob($evaluation, $this->items->count(['evaluation' => $evaluation]));
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        ($this->checks)(new RunAutomaticChecks($job->getId()->toRfc4122()));

        $scores = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ?', [$evaluation->getId()->toRfc4122()]);
        $io->success(\sprintf(
            '"%s" in project %s: %d cases, %d answers, %d scores, in %.1f s. Evaluation %s.',
            $evaluation->getName(),
            $evaluation->getProject()->getId()->toRfc4122(),
            $cases,
            $job->getTotal(),
            $scores,
            (hrtime(true) - $started) / 1e9,
            $evaluation->getId()->toRfc4122(),
        ));

        return Command::SUCCESS;
    }

    private function owner(mixed $email): ?User
    {
        if (\is_string($email)) {
            return $this->entityManager->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
        }
        $user = new User('performance-seed-'.bin2hex(random_bytes(4)).'@example.com', 'Performance seed');
        $user->setPasswordHash('!'); // no password hash matches it: this user cannot sign in
        $this->entityManager->persist($user);

        return $user;
    }

    private function seed(User $owner, int $caseCount, Randomizer $random): Evaluation
    {
        $project = new Project('Performance seed', 'Data at the MVP limits, made by app:seed-performance to time the results.');
        $dataset = new Dataset($project, 'Support cases at scale');
        $version = new DatasetVersion($dataset, 1, $caseCount, 'seed.csv', str_repeat('0', 64));
        foreach ([$project, new ProjectMember($project, $owner, ProjectRole::Owner), $dataset, $version] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        // Cases: 1 to 3 tags each; every 10th has no reference, so exact_match gives check errors (missing_reference).
        $caseIds = [];
        $rows = [];
        for ($position = 1; $position <= $caseCount; ++$position) {
            $caseIds[$position] = Uuid::v7()->toRfc4122();
            $tags = $random->pickArrayKeys(array_flip(self::TAGS), $random->getInt(1, 3));
            $order = 100000 + $position;
            $rows[] = [
                $caseIds[$position], $version->getId()->toRfc4122(), $position, \sprintf('c%05d', $position),
                "Where is my order #$order? It was due last week.", 0 === $position % 10 ? null : "Order #$order",
                json_encode($tags, \JSON_THROW_ON_ERROR), '{}',
            ];
        }
        $this->insert('test_case', ['id', 'dataset_version_id', 'position', 'case_key', 'input', 'reference', 'tags', 'metadata'], $rows);

        // Runs: the later ones are a little better; the last answers 96% of the cases (a partial run).
        $runs = [];
        foreach (self::RUNS as $index => $label) {
            $answered = array_filter(array_keys($caseIds), static fn (int $position): bool => 4 !== $index || 0 !== $position % 25);
            $run = new Run($project, $version, $label, "Seeded model $label", \count($answered), 'seed.csv', str_repeat('0', 64));
            $this->entityManager->persist($run);
            $this->entityManager->flush();
            $rows = [];
            foreach ($answered as $position) {
                $order = 100000 + $position;
                $output = match (true) {
                    $random->getInt(0, 9) < 6 + $index % 3 => "Order #$order",
                    $random->getInt(0, 1) > 0 => "Your Order #$order ships tomorrow. Sorry for the wait!",
                    default => 'I am sorry, I cannot find that order. Could you send the number again?',
                };
                $rows[] = [Uuid::v7()->toRfc4122(), $run->getId()->toRfc4122(), $caseIds[$position], $output];
            }
            $this->insert('answer', ['id', 'run_id', 'test_case_id', 'output'], $rows);
            $runs[] = $run;
        }

        $rubric = new Rubric($project, 'Support quality at scale');
        $rubric->replaceCriteria([
            new CriterionData(CriterionKind::Scale1To5, 'Tone', '1: rude or cold. 3: polite but generic. 5: warm and clear, without filler.', 2.0),
            new CriterionData(CriterionKind::Scale1To5, 'Accuracy', '1: wrong. 5: every fact right.', 3.0),
            new CriterionData(CriterionKind::PassFail, 'Follows the refund policy', 'Passes when the answer applies the 30-day refund rule.'),
            new CriterionData(CriterionKind::Automatic, 'Mentions the order number', checkType: CheckType::Regex, checkParams: ['pattern' => 'Order #[0-9]{6}', 'ignoreCase' => true]),
            new CriterionData(CriterionKind::Automatic, 'Short enough', weight: 0.5, checkType: CheckType::Length, checkParams: ['min' => 1, 'max' => 60]),
            new CriterionData(CriterionKind::Automatic, 'Matches the reference', checkType: CheckType::ExactMatch, checkParams: ['ignoreCase' => true]),
        ]);
        $rubric->lock();
        $evaluation = new Evaluation($project, $version, $rubric, 'Five models at scale', true);
        $this->entityManager->persist($rubric);
        $this->entityManager->persist($evaluation);
        foreach ($runs as $index => $run) {
            $this->entityManager->persist(new EvaluationRun($evaluation, $run, 'ABCDE'[$index]));
        }
        $this->entityManager->flush();
        $this->items->createFor($evaluation, $runs);

        // Every manual score, by the owner: run B (index 1) is the best on average, the others spread around A.
        $bias = [0.0, 0.7, -0.3, 0.3, -0.6];
        $manual = array_values(array_filter($rubric->getCriteria(), static fn ($criterion): bool => CriterionKind::Automatic !== $criterion->getData()->kind));
        $rows = [];
        foreach ($this->connection->iterateAssociative(
            'SELECT i.id, r.letter FROM evaluation_item i JOIN answer a ON a.id = i.answer_id JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id WHERE i.evaluation_id = ?',
            [$evaluation->getId()->toRfc4122()],
        ) as $item) {
            $shift = $bias[strpos('ABCDE', (string) $item['letter'])];
            foreach ($manual as $criterion) {
                $value = CriterionKind::Scale1To5 === $criterion->getData()->kind
                    ? max(1, min(5, (int) round(3.2 + $shift + ($random->getFloat(0, 1) + $random->getFloat(0, 1) - 1) * 2.4)))
                    : (int) ($random->getFloat(0, 1) < 0.7 + $shift / 4);
                $rows[] = [Uuid::v7()->toRfc4122(), (string) $item['id'], $criterion->getId()->toRfc4122(), $value, 'scored', 'manual', $owner->getId()->toRfc4122(), date(\DATE_ATOM)];
            }
            // In batches as it goes, so about 75,000 scores fit in PHP's default 128 MB.
            if (\count($rows) >= self::BATCH) {
                $this->insert('score', self::SCORE_COLUMNS, $rows);
                $rows = [];
            }
        }
        $this->insert('score', self::SCORE_COLUMNS, $rows);

        return $evaluation;
    }

    /**
     * @param list<string>      $columns
     * @param list<list<mixed>> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        $values = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        foreach (array_chunk($rows, self::BATCH) as $batch) {
            $this->connection->executeStatement(
                "INSERT INTO $table (".implode(', ', $columns).') VALUES '.implode(', ', array_fill(0, \count($batch), $values)),
                array_merge(...$batch),
            );
        }
    }
}
