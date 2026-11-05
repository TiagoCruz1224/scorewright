<?php

declare(strict_types=1);

namespace App\Tests;

use App\Users\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Authorization matrix (architecture §8, MVP criterion 2): the expected status of every route for every actor.
 * A new route fails testEveryRouteIsInTheMatrix until it is added here. Request bodies are minimal on purpose: the
 * matrix checks who gets through, the feature tests check what happens next.
 *
 * Each case runs against a fresh active project with an owner, an evaluator and a viewer, plus a signed-in user who
 * is not a member, and a dataset with one version. {projectId} is that project and {userId} its viewer;
 * {nonMemberEmail} is the non-member's email; {datasetId} and {versionId} are the dataset and its version.
 */
final class AuthorizationMatrixTest extends ApiTestCase
{
    private const ACTORS = ['anonymous', 'non-member', 'viewer', 'evaluator', 'owner'];

    /**
     * route name => [method, path, body, expected status for each of self::ACTORS, in that order]. The body is null,
     * JSON fields, or multipart fields when it has '@file' => the name of a file in tests/Datasets/fixtures.
     */
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
        'dataset_list' => ['GET', '/api/projects/{projectId}/datasets', null, [401, 404, 200, 200, 200]],
        'dataset_create' => ['POST', '/api/projects/{projectId}/datasets', ['name' => 'New dataset', '@file' => 'valid.csv'], [401, 404, 403, 403, 201]],
        'dataset_show' => ['GET', '/api/projects/{projectId}/datasets/{datasetId}', null, [401, 404, 200, 200, 200]],
        'dataset_delete' => ['DELETE', '/api/projects/{projectId}/datasets/{datasetId}', null, [401, 404, 403, 403, 204]],
        'dataset_version_create' => ['POST', '/api/projects/{projectId}/datasets/{datasetId}/versions', ['@file' => 'valid.json'], [401, 404, 403, 403, 201]],
        'dataset_cases' => ['GET', '/api/projects/{projectId}/dataset-versions/{versionId}/cases', null, [401, 404, 200, 200, 200]],
    ];

    /** Every route, not only those under /api: a route added elsewhere must be in the matrix too. */
    public function testEveryRouteIsInTheMatrix(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection()->all();

        self::assertEqualsCanonicalizing(array_keys(self::MATRIX), array_keys($routes), 'Every route needs an entry in AuthorizationMatrixTest::MATRIX, and only those.');
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
        $response = self::requestAs($route, $actor);

        $expected = array_combine(self::ACTORS, self::MATRIX[$route][3])[$actor];
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routesWithABody(): iterable
    {
        foreach (self::MATRIX as $route => [, , $body]) {
            if (null !== $body) {
                yield $route => [$route];
            }
        }
    }

    /** A form post to a JSON route, or JSON to an upload route, is refused before anything reads it. */
    #[DataProvider('routesWithABody')]
    public function testRoutesRefuseABodyOfTheWrongType(string $route): void
    {
        self::assertProblem(self::requestAs($route, 'owner', wrongBodyType: true), 415, 'unsupported_media_type');
    }

    /** Deny by default, outside /api too: a route someone adds anywhere needs a signed-in user unless listed. */
    public function testEveryPathButThePublicOnesNeedsASignedInUser(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);

        foreach (['/', '/admin/dummy', '/api', '/api/projects', '/api/healthz', '/api/auth/login/x'] as $path) {
            self::assertSame([['IS_AUTHENTICATED'], null], $accessMap->getPatterns(Request::create($path)), $path);
        }
        foreach (['/api/health', '/api/auth/register', '/api/auth/login', '/api/auth/logout'] as $path) {
            self::assertSame([['PUBLIC_ACCESS'], null], $accessMap->getPatterns(Request::create($path)), $path);
        }
    }

    /**
     * @param bool $wrongBodyType send a JSON route's body as a form, or an upload route's fields as JSON
     */
    private static function requestAs(string $route, string $actor, bool $wrongBodyType = false): Response
    {
        [$method, $path, $body] = self::MATRIX[$route];
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $version = self::createDataset($fixture['project']);
        if ('anonymous' !== $actor) {
            $user = $fixture[$actor];
            self::assertInstanceOf(User::class, $user);
            $client->loginUser($user);
        }

        $placeholders = [
            '{projectId}' => $fixture['project']->getId()->toRfc4122(),
            '{userId}' => $fixture['viewer']->getId()->toRfc4122(),
            '{nonMemberEmail}' => $fixture['non-member']->getEmail(),
            '{datasetId}' => $version->getDataset()->getId()->toRfc4122(),
            '{versionId}' => $version->getId()->toRfc4122(),
        ];
        $uri = strtr($path, $placeholders);
        $body = null === $body ? null : array_map(static fn (string $value): string => strtr($value, $placeholders), $body);

        if (isset($body['@file'])) {
            $file = $body['@file'];
            unset($body['@file']);

            return $wrongBodyType ? self::requestJson($client, $method, $uri, $body) : self::upload($client, $uri, self::fixturePath($file), $file, $body);
        }
        if (!$wrongBodyType) {
            return self::requestJson($client, $method, $uri, $body);
        }

        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: http_build_query($body ?? []));

        return $client->getResponse();
    }
}
