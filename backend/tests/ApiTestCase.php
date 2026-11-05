<?php

declare(strict_types=1);

namespace App\Tests;

use App\Datasets\Entity\Answer;
use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Datasets\Entity\TestCase;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\EvaluationItem;
use App\Evaluations\Entity\EvaluationRun;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Repository\EvaluationItemRepository;
use App\Projects\Entity\Project;
use App\Projects\Entity\ProjectMember;
use App\Projects\Entity\ProjectRole;
use App\Users\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/** Requests as the SPA makes them. Each test runs in a transaction that is rolled back (dama/doctrine-test-bundle). */
abstract class ApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'correct horse battery';

    /**
     * HTTPS, because the session cookie is Secure; the csrf-token header, as the SPA sends it. Login throttling starts
     * from zero, so tests (and quick re-runs) do not throttle each other.
     */
    protected static function createApiClient(): KernelBrowser
    {
        $client = static::createClient([], ['HTTPS' => 'on', 'HTTP_CSRF_TOKEN' => str_repeat('t', 32)]);
        $rateLimiterStorage = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterStorage);
        $rateLimiterStorage->clear();

        return $client;
    }

    /**
     * @param array<mixed>|null $body
     */
    protected static function requestJson(KernelBrowser $client, string $method, string $uri, ?array $body = null): Response
    {
        $content = null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR);
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $content);

        return $client->getResponse();
    }

    /**
     * @return array<mixed>
     */
    protected static function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    protected static function register(KernelBrowser $client, string $email, string $password = self::PASSWORD): Response
    {
        return self::requestJson($client, 'POST', '/api/auth/register', ['email' => $email, 'password' => $password, 'displayName' => 'Test user']);
    }

    protected static function login(KernelBrowser $client, string $email, string $password = self::PASSWORD): Response
    {
        return self::requestJson($client, 'POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    protected static function signIn(KernelBrowser $client, string $email): void
    {
        self::assertSame(201, self::register($client, $email)->getStatusCode());
        self::assertSame(200, self::login($client, $email)->getStatusCode());
    }

    /**
     * A project with an owner, an evaluator and a viewer, plus a user who is not a member. Created directly in the
     * database; tests sign these users in with $client->loginUser().
     *
     * @return array{project: Project, owner: User, evaluator: User, viewer: User, non-member: User}
     */
    protected static function createProjectWithMembers(string $name = 'Project'): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $project = new Project($name);
        $entityManager->persist($project);
        $users = [];
        foreach (['owner' => ProjectRole::Owner, 'evaluator' => ProjectRole::Evaluator, 'viewer' => ProjectRole::Viewer, 'non-member' => null] as $actor => $role) {
            $user = new User($actor.'-'.bin2hex(random_bytes(4)).'@example.com', ucfirst($actor));
            $user->setPasswordHash('unused: signed in with loginUser()');
            $entityManager->persist($user);
            if (null !== $role) {
                $entityManager->persist(new ProjectMember($project, $user, $role));
            }
            $users[$actor] = $user;
        }
        $entityManager->flush();

        return ['project' => $project, 'owner' => $users['owner'], 'evaluator' => $users['evaluator'], 'viewer' => $users['viewer'], 'non-member' => $users['non-member']];
    }

    /** A dataset with version 1 and two cases, created directly in the database. */
    protected static function createDataset(Project $project, string $name = 'Dataset'): DatasetVersion
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $dataset = new Dataset($project, $name);
        $version = new DatasetVersion($dataset, 1, 2, 'cases.csv', str_repeat('0', 64));
        $entityManager->persist($dataset);
        $entityManager->persist($version);
        $entityManager->persist(new TestCase($version, 1, 'a', 'First input', 'First reference', ['billing'], ['source' => 'test']));
        $entityManager->persist(new TestCase($version, 2, 'b', 'Second input'));
        $entityManager->flush();

        return $version;
    }

    /** A run of the version from createDataset(), answering case "a" only, created directly in the database. */
    protected static function createRun(DatasetVersion $version, string $label = 'Baseline', string $output = 'Answer to a'): Run
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $case = $entityManager->getRepository(TestCase::class)->findOneBy(['datasetVersion' => $version, 'caseKey' => 'a']);
        self::assertInstanceOf(TestCase::class, $case);

        $run = new Run($version->getDataset()->getProject(), $version, $label, 'A model', 1, 'run.csv', str_repeat('0', 64));
        $entityManager->persist($run);
        $entityManager->persist(new Answer($run, $case, $output));
        $entityManager->flush();

        return $run;
    }

    /** An unlocked rubric with a scale_1_5 criterion and a contains check, created directly in the database. */
    protected static function createRubric(Project $project, string $name = 'Quality'): Rubric
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $rubric = new Rubric($project, $name);
        $rubric->replaceCriteria([
            new CriterionData(CriterionKind::Scale1To5, 'Tone', '1: rude. 5: warm.', 2.0),
            new CriterionData(CriterionKind::Automatic, 'Mentions a refund', checkType: CheckType::Contains, checkParams: ['text' => 'refund', 'ignoreCase' => true]),
        ]);
        $entityManager->persist($rubric);
        $entityManager->flush();

        return $rubric;
    }

    /**
     * An evaluation of the runs (letters A, B… in this order) with the rubric, made as the API makes it: its items by
     * EvaluationItemRepository::createFor, and the rubric locked.
     *
     * @param non-empty-list<Run> $runs
     */
    protected static function createEvaluation(Rubric $rubric, array $runs, bool $blind = false, bool $closed = false, string $name = 'Evaluation'): Evaluation
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $items = self::getContainer()->get(EvaluationItemRepository::class);
        self::assertInstanceOf(EvaluationItemRepository::class, $items);

        $evaluation = new Evaluation($rubric->getProject(), $runs[0]->getDatasetVersion(), $rubric, $name, $blind);
        $rubric->lock();
        $entityManager->persist($evaluation);
        foreach ($runs as $index => $run) {
            $entityManager->persist(new EvaluationRun($evaluation, $run, 'ABCDE'[$index]));
        }
        $entityManager->flush();
        $items->createFor($evaluation, $runs);
        if ($closed) {
            $evaluation->close();
            $entityManager->flush();
        }

        return $evaluation;
    }

    /** The evaluation's first item by position. */
    protected static function firstItem(Evaluation $evaluation): EvaluationItem
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $item = $entityManager->getRepository(EvaluationItem::class)->findOneBy(['evaluation' => $evaluation], ['position' => 'ASC']);
        self::assertInstanceOf(EvaluationItem::class, $item);

        return $item;
    }

    protected static function fixturePath(string $name): string
    {
        return __DIR__.'/Datasets/fixtures/'.$name;
    }

    /**
     * A multipart request, as the SPA sends a file: the "file" field plus $fields. $path is the file on disk and
     * $clientName the name the browser reports.
     *
     * @param array<string, string> $fields
     */
    protected static function upload(KernelBrowser $client, string $uri, string $path, string $clientName, array $fields = []): Response
    {
        $client->request('POST', $uri, $fields, ['file' => new UploadedFile($path, $clientName, null, null, true)], ['CONTENT_TYPE' => 'multipart/form-data; boundary=scorewright']);

        return $client->getResponse();
    }

    /**
     * Like upload(), for content generated by the test.
     *
     * @param array<string, string> $fields
     */
    protected static function uploadContent(KernelBrowser $client, string $uri, string $content, string $clientName, array $fields = []): Response
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $content);
        try {
            return self::upload($client, $uri, $path, $clientName, $fields);
        } finally {
            unlink($path);
        }
    }

    protected static function assertProblem(Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame($code, self::json($response)['code']);
    }

    /** Retry-After in whole seconds: at least 1, and at most two windows (a sliding window can make it longer than one). */
    protected static function assertRetryAfter(Response $response, int $windowSeconds): void
    {
        self::assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
        self::assertLessThanOrEqual(2 * $windowSeconds, (int) $response->headers->get('Retry-After'));
    }
}
