<?php

declare(strict_types=1);

namespace App\Tests;

use App\Users\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * Authorization matrix (architecture §8, MVP criterion 2): the expected status of every /api route for every actor.
 * A new route fails testEveryApiRouteIsInTheMatrix until it is added here. Request bodies are minimal on purpose: the
 * matrix checks who gets through, the feature tests check what happens next.
 *
 * Each case runs against a fresh active project with an owner, an evaluator and a viewer, plus a signed-in user who
 * is not a member. {projectId} is that project and {userId} its viewer; {nonMemberEmail} is the non-member's email.
 */
final class AuthorizationMatrixTest extends ApiTestCase
{
    private const ACTORS = ['anonymous', 'non-member', 'viewer', 'evaluator', 'owner'];

    /** route name => [method, path, JSON body or null, expected status for each of self::ACTORS, in that order] */
    private const MATRIX = [
        'health' => ['GET', '/api/health', null, [200, 200, 200, 200, 200]],
        'auth_register' => ['POST', '/api/auth/register', [], [422, 422, 422, 422, 422]],
        'auth_login' => ['POST', '/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'not the password'], [401, 401, 401, 401, 401]],
        'auth_logout' => ['POST', '/api/auth/logout', null, [204, 204, 204, 204, 204]],
        'me' => ['GET', '/api/me', null, [401, 200, 200, 200, 200]],
        'project_list' => ['GET', '/api/projects', null, [401, 200, 200, 200, 200]],
        'project_create' => ['POST', '/api/projects', ['name' => 'New project'], [401, 201, 201, 201, 201]],
        'project_show' => ['GET', '/api/projects/{projectId}', null, [401, 404, 200, 200, 200]],
        'project_update' => ['PATCH', '/api/projects/{projectId}', ['name' => 'Renamed'], [401, 404, 403, 403, 200]],
        'project_archive' => ['POST', '/api/projects/{projectId}/archive', null, [401, 404, 403, 403, 200]],
        'project_restore' => ['POST', '/api/projects/{projectId}/restore', null, [401, 404, 403, 403, 200]],
        'member_list' => ['GET', '/api/projects/{projectId}/members', null, [401, 404, 403, 403, 200]],
        'member_invite' => ['POST', '/api/projects/{projectId}/members', ['email' => '{nonMemberEmail}', 'role' => 'viewer'], [401, 404, 403, 403, 201]],
        'member_update' => ['PATCH', '/api/projects/{projectId}/members/{userId}', ['role' => 'evaluator'], [401, 404, 403, 403, 200]],
        'member_remove' => ['DELETE', '/api/projects/{projectId}/members/{userId}', null, [401, 404, 403, 403, 204]],
    ];

    public function testEveryApiRouteIsInTheMatrix(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/')) {
                $routes[$name] = $route;
            }
        }

        self::assertEqualsCanonicalizing(array_keys(self::MATRIX), array_keys($routes), 'Every /api route needs an entry in AuthorizationMatrixTest::MATRIX, and only those.');
        foreach ($routes as $name => $route) {
            [$method, $path] = self::MATRIX[$name];
            self::assertSame($route->getPath(), $path, "Path of route \"$name\"");
            self::assertSame([$method], $route->getMethods(), "Methods of route \"$name\"");
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routesAndActors(): iterable
    {
        foreach (array_keys(self::MATRIX) as $route) {
            foreach (self::ACTORS as $actor) {
                yield "$route as $actor" => [$route, $actor];
            }
        }
    }

    #[DataProvider('routesAndActors')]
    public function testRouteAnswersTheExpectedStatusForEachActor(string $route, string $actor): void
    {
        [$method, $path, $body, $statuses] = self::MATRIX[$route];
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        if ('anonymous' !== $actor) {
            $user = $fixture[$actor];
            self::assertInstanceOf(User::class, $user);
            $client->loginUser($user);
        }

        $placeholders = [
            '{projectId}' => $fixture['project']->getId()->toRfc4122(),
            '{userId}' => $fixture['viewer']->getId()->toRfc4122(),
            '{nonMemberEmail}' => $fixture['non-member']->getEmail(),
        ];
        $body = null === $body ? null : array_map(static fn (string $value): string => strtr($value, $placeholders), $body);
        $response = self::requestJson($client, $method, strtr($path, $placeholders), $body);

        $expected = array_combine(self::ACTORS, $statuses)[$actor];
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
    }
}
