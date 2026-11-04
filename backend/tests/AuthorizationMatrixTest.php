<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * Authorization matrix (architecture §8, MVP criterion 2): the expected status of every /api route for every actor.
 * A new route fails testEveryApiRouteIsInTheMatrix until it is added here. Request bodies are minimal on purpose: the
 * matrix checks who gets through, the feature tests check what happens next.
 */
final class AuthorizationMatrixTest extends ApiTestCase
{
    /** route name => [method, path, JSON body or null, [actor => expected status]] */
    private const MATRIX = [
        'health' => ['GET', '/api/health', null, ['anonymous' => 200, 'user' => 200]],
        'auth_register' => ['POST', '/api/auth/register', [], ['anonymous' => 422, 'user' => 422]],
        'auth_login' => ['POST', '/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'not the password'], ['anonymous' => 401, 'user' => 401]],
        'auth_logout' => ['POST', '/api/auth/logout', null, ['anonymous' => 204, 'user' => 204]],
        'me' => ['GET', '/api/me', null, ['anonymous' => 401, 'user' => 200]],
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
        foreach (self::MATRIX as $route => [, , , $expected]) {
            foreach (array_keys($expected) as $actor) {
                yield "$route as $actor" => [$route, $actor];
            }
        }
    }

    #[DataProvider('routesAndActors')]
    public function testRouteAnswersTheExpectedStatusForEachActor(string $route, string $actor): void
    {
        [$method, $path, $body, $expected] = self::MATRIX[$route];
        $client = self::createApiClient();
        if ('user' === $actor) {
            self::signIn($client, 'matrix@example.com');
        }

        $response = self::requestJson($client, $method, $path, $body);

        self::assertSame($expected[$actor], $response->getStatusCode(), (string) $response->getContent());
    }
}
