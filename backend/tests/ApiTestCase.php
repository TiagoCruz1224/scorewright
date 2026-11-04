<?php

declare(strict_types=1);

namespace App\Tests;

use App\Projects\Entity\Project;
use App\Projects\Entity\ProjectMember;
use App\Projects\Entity\ProjectRole;
use App\Users\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    protected static function assertProblem(Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame($code, self::json($response)['code']);
    }
}
