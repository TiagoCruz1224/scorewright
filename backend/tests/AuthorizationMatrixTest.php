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
 * is not a member, a dataset with one version, a run of that version and a rubric. {projectId} is that project and
 * {userId} its viewer; {nonMemberEmail} is the non-member's email; {datasetId}, {versionId}, {runId} and {rubricId}
 * are the dataset, its version, the run and the rubric. The routes of one evaluation also get an evaluation of that run
 * with that rubric, in each state: {evaluationId}, its {itemId} and the rubric's manual {criterionId}.
 */
final class AuthorizationMatrixTest extends ApiTestCase
{
    private const ACTORS = ['anonymous', 'non-member', 'viewer', 'evaluator', 'owner'];

    private const CRITERIA = [['kind' => 'pass_fail', 'name' => 'Correct', 'guidance' => 'Pass when the facts are right.']];

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
        'dataset_version_list' => ['GET', '/api/projects/{projectId}/dataset-versions', null, [401, 404, 200, 200, 200]],
        'run_list' => ['GET', '/api/projects/{projectId}/runs', null, [401, 404, 200, 200, 200]],
        'run_create' => ['POST', '/api/projects/{projectId}/runs', ['datasetVersion' => '{versionId}', 'label' => 'New run', '@file' => 'run-for-a-b.csv'], [401, 404, 403, 403, 201]],
        'run_show' => ['GET', '/api/projects/{projectId}/runs/{runId}', null, [401, 404, 200, 200, 200]],
        'run_delete' => ['DELETE', '/api/projects/{projectId}/runs/{runId}', null, [401, 404, 403, 403, 204]],
        'rubric_list' => ['GET', '/api/projects/{projectId}/rubrics', null, [401, 404, 200, 200, 200]],
        'rubric_create' => ['POST', '/api/projects/{projectId}/rubrics', ['name' => 'New rubric', 'criteria' => self::CRITERIA], [401, 404, 403, 403, 201]],
        'rubric_show' => ['GET', '/api/projects/{projectId}/rubrics/{rubricId}', null, [401, 404, 200, 200, 200]],
        'rubric_update' => ['PUT', '/api/projects/{projectId}/rubrics/{rubricId}', ['name' => 'Renamed rubric', 'criteria' => self::CRITERIA], [401, 404, 403, 403, 200]],
        'rubric_delete' => ['DELETE', '/api/projects/{projectId}/rubrics/{rubricId}', null, [401, 404, 403, 403, 204]],
        'rubric_duplicate' => ['POST', '/api/projects/{projectId}/rubrics/{rubricId}/duplicate', ['name' => 'Copy'], [401, 404, 403, 403, 201]],
        'evaluation_list' => ['GET', '/api/projects/{projectId}/evaluations', null, [401, 404, 200, 200, 200]],
        'evaluation_create' => ['POST', '/api/projects/{projectId}/evaluations', ['name' => 'New evaluation', 'datasetVersion' => '{versionId}', 'runs' => ['{runId}'], 'rubric' => '{rubricId}'], [401, 404, 403, 403, 201]],
        'evaluation_show' => ['GET', '/api/projects/{projectId}/evaluations/{evaluationId}', null, [401, 404, 200, 200, 200]],
        'evaluation_close' => ['POST', '/api/projects/{projectId}/evaluations/{evaluationId}/close', null, [401, 404, 403, 403, 200]],
        'evaluation_automatic_checks' => ['POST', '/api/projects/{projectId}/evaluations/{evaluationId}/automatic-checks', null, [401, 404, 403, 403, 202]],
        'evaluation_delete' => ['DELETE', '/api/projects/{projectId}/evaluations/{evaluationId}', null, [401, 404, 403, 403, 204]],
        'evaluation_item_next' => ['GET', '/api/projects/{projectId}/evaluations/{evaluationId}/items/next', null, [401, 404, 403, 200, 200]],
        'evaluation_item_show' => ['GET', '/api/projects/{projectId}/evaluations/{evaluationId}/items/{itemId}', null, [401, 404, 403, 200, 200]],
        'evaluation_score' => ['PUT', '/api/projects/{projectId}/evaluations/{evaluationId}/items/{itemId}/scores/{criterionId}', ['value' => 3], [401, 404, 403, 200, 200]],
        'evaluation_comment' => ['PUT', '/api/projects/{projectId}/evaluations/{evaluationId}/items/{itemId}/comment', ['comment' => 'Fine'], [401, 404, 403, 200, 200]],
    ];

    /**
     * The routes of one evaluation run in each state: open or closed, blind or not. MATRIX gives the status of an open
     * evaluation; a closed one answers these instead (closing is final, and scoring stops: 409 evaluation_closed). Blind
     * mode changes what the scoring routes say, not who may call them (EvaluationTest checks what they say).
     */
    private const STATES = ['open', 'open and blind', 'closed', 'closed and blind'];
    private const WHEN_CLOSED = [
        'evaluation_close' => [401, 404, 403, 403, 409],
        'evaluation_automatic_checks' => [401, 404, 403, 403, 409],
        'evaluation_item_next' => [401, 404, 403, 409, 409],
        'evaluation_item_show' => [401, 404, 403, 409, 409],
        'evaluation_score' => [401, 404, 403, 409, 409],
        'evaluation_comment' => [401, 404, 403, 409, 409],
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
        foreach (self::MATRIX as $route => [, $path]) {
            if (str_contains($path, '{evaluationId}')) {
                continue; // in every state, below
            }
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
     * @return iterable<string, array{string, string, string}>
     */
    public static function evaluationRoutesActorsAndStates(): iterable
    {
        foreach (self::MATRIX as $route => [, $path]) {
            if (!str_contains($path, '{evaluationId}')) {
                continue;
            }
            foreach (self::STATES as $state) {
                foreach (self::ACTORS as $actor) {
                    yield "$route on an evaluation $state, as $actor" => [$route, $actor, $state];
                }
            }
        }
    }

    #[DataProvider('evaluationRoutesActorsAndStates')]
    public function testEvaluationRouteAnswersTheExpectedStatusInEachState(string $route, string $actor, string $state): void
    {
        $response = self::requestAs($route, $actor, state: $state);

        $statuses = str_starts_with($state, 'closed') ? self::WHEN_CLOSED[$route] ?? self::MATRIX[$route][3] : self::MATRIX[$route][3];
        self::assertSame(array_combine(self::ACTORS, $statuses)[$actor], $response->getStatusCode(), (string) $response->getContent());
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
     * @param bool   $wrongBodyType send a JSON route's body as a form, or an upload route's fields as JSON
     * @param string $state         for the routes of one evaluation: one of self::STATES
     */
    private static function requestAs(string $route, string $actor, bool $wrongBodyType = false, string $state = 'open'): Response
    {
        [$method, $path, $body] = self::MATRIX[$route];
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $version = self::createDataset($fixture['project']);
        $run = self::createRun($version);
        $rubric = self::createRubric($fixture['project']);
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
            '{runId}' => $run->getId()->toRfc4122(),
            '{rubricId}' => $rubric->getId()->toRfc4122(),
        ];
        // Only for the routes of one evaluation: an evaluation keeps its rubric, run and dataset from changing.
        if (str_contains($path, '{evaluationId}')) {
            $evaluation = self::createEvaluation($rubric, [$run], blind: str_contains($state, 'blind'), closed: str_starts_with($state, 'closed'));
            $item = self::firstItem($evaluation);
            $placeholders += [
                '{evaluationId}' => $evaluation->getId()->toRfc4122(),
                '{itemId}' => $item->getId()->toRfc4122(),
                '{criterionId}' => $rubric->getCriteria()[0]->getId()->toRfc4122(), // Tone, a scale_1_5 criterion
            ];
        }
        $uri = strtr($path, $placeholders);
        $fill = static function (mixed $value) use (&$fill, $placeholders): mixed {
            return match (true) {
                \is_string($value) => strtr($value, $placeholders),
                \is_array($value) => array_map($fill, $value),
                default => $value,
            };
        };
        $body = null === $body ? null : array_map($fill, $body);

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
