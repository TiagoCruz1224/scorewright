<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * docs/api.md documents every route of the router, and nothing else: the method and the path of each, whatever the
 * parameters are called. An endpoint is documented in a heading (### `GET /api/me`), in the first cell of a table
 * (| `GET P/runs` |) or at the start of a paragraph, where `P` stands for /api/projects/{projectId}.
 */
final class ApiReferenceTest extends KernelTestCase
{
    public function testTheApiReferenceListsExactlyTheRoutesOfTheRouter(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = [];
        foreach ($router->getRouteCollection() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes[] = $method.' '.self::normalise($route->getPath());
            }
        }

        // Mounted at /docs in the containers (compose.yaml); next to backend/ in a checkout.
        $file = is_file('/docs/api.md') ? '/docs/api.md' : \dirname(__DIR__, 2).'/docs/api.md';
        preg_match_all('/^(?:### |\| )?`(GET|POST|PUT|PATCH|DELETE) ([^`?\s]+)/m', (string) file_get_contents($file), $matches, \PREG_SET_ORDER);
        $documented = array_map(static fn (array $match): string => $match[1].' '.self::normalise((string) preg_replace('#^P/#', '/api/projects/{projectId}/', $match[2])), $matches);

        self::assertNotEmpty($routes);
        self::assertSame([], array_values(array_diff($routes, $documented)), 'Routes that docs/api.md does not document');
        self::assertSame([], array_values(array_diff($documented, $routes)), 'Endpoints in docs/api.md that the router does not have');
    }

    private static function normalise(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
