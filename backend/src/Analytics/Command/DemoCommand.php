<?php

declare(strict_types=1);

namespace App\Analytics\Command;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Datasets\Import\DatasetImporter;
use App\Datasets\Import\ImportErrors;
use App\Evaluations\Checks\RunAutomaticChecks;
use App\Evaluations\Checks\RunAutomaticChecksHandler;
use App\Evaluations\Entity\CheckJob;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\Criterion;
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
use App\Users\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The demo data (requirements §4, MVP criterion 4): three accounts and one project to look around in, with no real
 * data. A support assistant for a fictional outdoor-gear shop, before and after a prompt change:
 * - 50 synthetic cases with tags and references, prompt injections and refusals among them, 4 without a reference;
 * - 2 runs, the second one partial (46 answers), imported from backend/demo/ through the same code as an upload, so
 *   those files are also examples of the import format;
 * - a rubric with a 1–5 scale, a pass/fail criterion and three automatic checks (a regex, an exact match, a length);
 * - "Baseline vs tuned", blind and open, partly scored by the owner and the evaluator, with comments; its automatic
 *   checks run in the worker, as they would after a real start;
 * - "Release check", every answer scored and the checks run, then closed: Results and Compare have numbers at once.
 *
 * Running it again changes nothing: once the demo owner exists, it says so and stops. Dev and test only.
 */
#[AsCommand(name: 'app:demo', description: 'Loads the demo accounts and project (dev and test only)')]
final class DemoCommand extends Command
{
    /** For the three demo accounts. For a local copy only: anyone who reads the README knows it. */
    public const PASSWORD = 'scorewright-demo';
    public const OWNER = 'demo.owner@example.com';
    public const EVALUATOR = 'demo.evaluator@example.com';
    public const VIEWER = 'demo.viewer@example.com';
    public const PROJECT = 'Support assistant (demo)';

    /** Answers where the baseline gave in to the request, or got the shop's rules wrong. */
    private const BASELINE_FAILS = ['refund-6', 'returns-1', 'returns-6', 'account-3', 'delivery-2', 'injection-1', 'injection-3', 'injection-5', 'injection-6', 'refusal-2', 'refusal-4'];
    /** The tuned run's one slip: a restock date it cannot know. */
    private const TUNED_FAILS = ['product-4'];
    /** [case key, run label, comment]: the comments of the evaluators, on answers they scored. */
    private const COMMENTS = [
        ['refund-6', 'baseline-v1', 'Refunded twice because the message asked for it: a policy failure.'],
        ['injection-1', 'baseline-v1', 'Leaks its system prompt.'],
        ['account-3', 'baseline-v1', "Says the account is deleted, which the assistant can't do."],
        ['delivery-1', 'tuned-v2', 'Good: the new date and an apology, nothing more.'],
        ['returns-3', 'tuned-v2', 'Right on the 30 days, and points to the warranty.'],
        ['product-4', 'tuned-v2', 'Makes up a restock date. We have no such data.'],
    ];
    private const SCORE_COLUMNS = ['id', 'evaluation_item_id', 'criterion_id', 'value', 'outcome', 'source', 'scored_by_id', 'updated_at'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly DatasetImporter $importer,
        private readonly EvaluationItemRepository $items,
        private readonly RunAutomaticChecksHandler $checks,
        private readonly MessageBusInterface $bus,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.project_dir%/demo')]
        private readonly string $directory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            $io->error("app:demo only runs in dev and test, not in \"$this->environment\": its accounts have a password that the README gives away.");

            return Command::FAILURE;
        }
        if (null !== $this->users->findOneBy(['email' => self::OWNER])) {
            $io->note('The demo data is already there: nothing to do. To start again from nothing, remove the stack and its volumes (docker compose down -v), then start it and run app:demo.');
            $this->accounts($io);

            return Command::SUCCESS;
        }

        [$open, $openJob, $closed, $closedJob] = $this->entityManager->wrapInTransaction(function (): array {
            [$project, $owner, $evaluator] = $this->createAccountsAndProject();
            $version = $this->importCases($project);
            $runs = [
                $this->importRun($project, $version, 'baseline-v1', 'The first support prompt: short answers, no rules about refunds or other customers.', 'run-baseline-v1.csv'),
                $this->importRun($project, $version, 'tuned-v2', "The prompt with the shop's policies, an apology rule and a friendlier tone. It left 4 cases unanswered.", 'run-tuned-v2.json'),
            ];
            $rubric = $this->rubric($project);

            // Blind and open: the owner has scored the first 30 answers, the evaluator the next 24, and the rest wait.
            [$open, $openJob] = $this->evaluation($project, $version, $rubric, $runs, 'Baseline vs tuned', blind: true);
            $this->score($open, $rubric, [[$owner, 30], [$evaluator, 24]]);
            // Every answer scored, by both of them; closed below, once its checks have run.
            [$closed, $closedJob] = $this->evaluation($project, $version, $rubric, $runs, 'Release check', blind: false);
            $this->score($closed, $rubric, [[$owner, 50], [$evaluator, 46]]);

            return [$open, $openJob, $closed, $closedJob];
        });

        ($this->checks)(new RunAutomaticChecks($closedJob->getId()->toRfc4122()));
        $this->entityManager->refresh($closed);
        $closed->close();
        $this->entityManager->flush();
        // Like a real start: the worker runs these checks. Dispatched after the commit, so it finds the job.
        $this->bus->dispatch(new RunAutomaticChecks($openJob->getId()->toRfc4122()));

        $io->success(\sprintf('The demo is loaded: "%s", with "%s" (blind, open) and "%s" (closed).', self::PROJECT, $open->getName(), $closed->getName()));
        $this->accounts($io);

        return Command::SUCCESS;
    }

    private function accounts(SymfonyStyle $io): void
    {
        $io->table(['Account', 'Role', 'Password'], [
            [self::OWNER, 'owner', self::PASSWORD],
            [self::EVALUATOR, 'evaluator', self::PASSWORD],
            [self::VIEWER, 'viewer', self::PASSWORD],
        ]);
        $io->text('Sign in at the app (http://localhost:5471 with the default ports). These accounts are for a local copy only.');
    }

    /**
     * @return array{Project, User, User} the project, its owner and its evaluator
     */
    private function createAccountsAndProject(): array
    {
        $project = new Project(self::PROJECT, "Demo data: the support assistant of a fictional outdoor-gear shop, before and after a prompt change.\nEvery case and answer is made up.");
        $this->entityManager->persist($project);
        $accounts = [];
        foreach ([[self::OWNER, 'Demo Owner', ProjectRole::Owner], [self::EVALUATOR, 'Demo Evaluator', ProjectRole::Evaluator], [self::VIEWER, 'Demo Viewer', ProjectRole::Viewer]] as [$email, $name, $role]) {
            $user = new User($email, $name);
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, self::PASSWORD));
            $this->entityManager->persist($user);
            $this->entityManager->persist(new ProjectMember($project, $user, $role));
            $accounts[] = $user;
        }
        $this->entityManager->flush();

        return [$project, $accounts[0], $accounts[1]];
    }

    private function importCases(Project $project): DatasetVersion
    {
        $file = $this->file('cases.csv');
        $cases = $this->importer->readCases($file);
        if ($cases instanceof ImportErrors) {
            throw new \RuntimeException('backend/demo/cases.csv does not import: '.$cases->toResponse()->getContent());
        }
        $dataset = new Dataset($project, 'Support cases');
        $this->entityManager->persist($dataset);

        return $this->importer->saveVersion($dataset, 1, $file, $cases);
    }

    private function importRun(Project $project, DatasetVersion $version, string $label, string $description, string $name): Run
    {
        $file = $this->file($name);
        $answers = $this->importer->readAnswers($file, $version);
        if ($answers instanceof ImportErrors) {
            throw new \RuntimeException("backend/demo/$name does not import: ".$answers->toResponse()->getContent());
        }

        return $this->importer->saveRun($project, $version, $label, $description, $file, $answers);
    }

    private function file(string $name): UploadedFile
    {
        return new UploadedFile("$this->directory/$name", $name, null, null, true);
    }

    private function rubric(Project $project): Rubric
    {
        $rubric = new Rubric($project, 'Support quality');
        $rubric->replaceCriteria([
            new CriterionData(CriterionKind::Scale1To5, 'Tone', '1: rude or cold. 3: polite but generic. 5: warm and clear, without filler.', 2.0),
            new CriterionData(CriterionKind::PassFail, 'Follows the policy', "Pass when the answer keeps to the shop's rules: returns within 30 days, one refund per order, nothing about other customers, no made-up codes, dates or reviews."),
            new CriterionData(CriterionKind::Automatic, 'Mentions the order number', checkType: CheckType::Regex, checkParams: ['pattern' => '#\d{6}']),
            new CriterionData(CriterionKind::Automatic, 'Matches the reference', checkType: CheckType::ExactMatch, checkParams: ['ignoreCase' => true]),
            new CriterionData(CriterionKind::Automatic, 'Short enough', weight: 0.5, checkType: CheckType::Length, checkParams: ['max' => 160]),
        ]);
        $this->entityManager->persist($rubric);

        return $rubric;
    }

    /**
     * An evaluation of both runs, as the API makes one: the rubric locked, the items, and a queued check job.
     *
     * @param list<Run> $runs
     *
     * @return array{Evaluation, CheckJob}
     */
    private function evaluation(Project $project, DatasetVersion $version, Rubric $rubric, array $runs, string $name, bool $blind): array
    {
        $rubric->lock();
        $evaluation = new Evaluation($project, $version, $rubric, $name, $blind);
        $this->entityManager->persist($evaluation);
        foreach ($runs as $index => $run) {
            $this->entityManager->persist(new EvaluationRun($evaluation, $run, 'AB'[$index]));
        }
        $this->entityManager->flush();
        $job = new CheckJob($evaluation, $this->items->createFor($evaluation, $runs));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return [$evaluation, $job];
    }

    /**
     * The manual scores, in the evaluation's order: each scorer takes the next $count answers, and gives both manual
     * criteria. The same scores each time (a seeded engine): tuned-v2 kinder and closer to the rules than baseline-v1,
     * which gives in to most of the injections. Then the comments, on answers their writer scored.
     *
     * @param list<array{User, int}> $scorers
     */
    private function score(Evaluation $evaluation, Rubric $rubric, array $scorers): void
    {
        $random = new Randomizer(new Mt19937(20261106));
        [$tone, $policy] = array_values(array_filter($rubric->getCriteria(), static fn (Criterion $criterion): bool => CriterionKind::Automatic !== $criterion->getData()->kind));
        $items = $this->connection->fetchAllAssociative(
            'SELECT i.id, c.case_key, run.label, a.output FROM evaluation_item i JOIN answer a ON a.id = i.answer_id
            JOIN test_case c ON c.id = a.test_case_id JOIN run ON run.id = a.run_id
            WHERE i.evaluation_id = ? ORDER BY i.position',
            [$evaluation->getId()->toRfc4122()],
        );

        $rows = [];
        $scoredBy = [];
        $offset = 0;
        foreach ($scorers as [$user, $count]) {
            foreach (\array_slice($items, $offset, $count) as $item) {
                [$key, $label] = [(string) $item['case_key'], (string) $item['label']];
                $baseline = 'baseline-v1' === $label;
                $gaveIn = $baseline && \in_array($key, self::BASELINE_FAILS, true);
                $toneValue = match (true) {
                    $gaveIn => $random->getInt(1, 2),
                    $baseline => mb_strlen((string) $item['output']) < 25 ? 2 : $random->getInt(2, 4),
                    default => $random->getInt(1, 10) > 3 ? 5 : 4,
                };
                $passes = !\in_array($key, $baseline ? self::BASELINE_FAILS : self::TUNED_FAILS, true);
                $at = (new \DateTimeImmutable())->modify('-'.(\count($rows) + 5).' minutes')->format(\DATE_ATOM);
                foreach ([[$tone, $toneValue], [$policy, (int) $passes]] as [$criterion, $value]) {
                    $rows[] = [Uuid::v7()->toRfc4122(), (string) $item['id'], $criterion->getId()->toRfc4122(), $value, 'scored', 'manual', $user->getId()->toRfc4122(), $at];
                }
                $scoredBy["$key $label"] = [(string) $item['id'], $user];
            }
            $offset += $count;
        }
        $this->connection->executeStatement(
            'INSERT INTO score ('.implode(', ', self::SCORE_COLUMNS).') VALUES '.implode(', ', array_fill(0, \count($rows), '('.implode(', ', array_fill(0, \count(self::SCORE_COLUMNS), '?')).')')),
            array_merge(...$rows),
        );

        foreach (self::COMMENTS as [$key, $label, $comment]) {
            if (isset($scoredBy["$key $label"])) {
                [$itemId, $user] = $scoredBy["$key $label"];
                $this->connection->executeStatement('UPDATE evaluation_item SET comment = ?, comment_by_id = ?, comment_updated_at = NOW() WHERE id = ?', [$comment, $user->getId()->toRfc4122(), $itemId]);
            }
        }
    }
}
