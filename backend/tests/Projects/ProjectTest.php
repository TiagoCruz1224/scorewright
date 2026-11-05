<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ProjectTest extends ApiTestCase
{
    public function testTheCreatorBecomesOwnerAndSeesTheProjectInTheList(): void
    {
        $client = self::createApiClient();
        $client->loginUser(self::createProjectWithMembers()['non-member']);

        $created = self::requestJson($client, 'POST', '/api/projects', ['name' => '  Support bot  ', 'description' => 'Refusals and injections']);
        self::assertSame(201, $created->getStatusCode());
        $project = self::json($created);
        self::assertSame(['name' => 'Support bot', 'description' => 'Refusals and injections', 'archivedAt' => null, 'role' => 'owner'], array_diff_key($project, ['id' => true]));

        $list = self::json(self::requestJson($client, 'GET', '/api/projects'));
        self::assertSame(['items' => [$project], 'page' => 1, 'perPage' => 25, 'total' => 1], $list);
    }

    public function testTheListHidesArchivedProjectsUnlessAskedForThem(): void
    {
        $client = self::createApiClient();
        $client->loginUser(self::createProjectWithMembers()['non-member']);
        $active = self::createProject($client, 'Active');
        $archived = self::createProject($client, 'Archived');
        self::requestJson($client, 'POST', "/api/projects/$archived/archive");

        self::assertSame([$active], self::listedIds($client, '/api/projects'));
        self::assertSame([$archived], self::listedIds($client, '/api/projects?archived=true'));
    }

    public function testTheListIsPaginatedAndOrderedByName(): void
    {
        $client = self::createApiClient();
        $client->loginUser(self::createProjectWithMembers()['non-member']);
        self::createProject($client, 'Charlie');
        self::createProject($client, 'Alpha');
        $bravo = self::createProject($client, 'Bravo');

        $page = self::json(self::requestJson($client, 'GET', '/api/projects?page=2&perPage=1'));
        self::assertSame(3, $page['total']);
        self::assertSame([$bravo], array_column($page['items'], 'id'));

        self::assertProblem(self::requestJson($client, 'GET', '/api/projects?perPage=101'), 422, 'validation_failed');
        self::assertProblem(self::requestJson($client, 'GET', '/api/projects?sort=name'), 422, 'validation_failed');
        self::assertSame([], self::json(self::requestJson($client, 'GET', '/api/projects?page=10000&perPage=100'))['items']);
        foreach (['10001', '4611686018427387904', '9223372036854775807'] as $page) {
            self::assertProblem(self::requestJson($client, 'GET', "/api/projects?page=$page&perPage=100"), 422, 'validation_failed');
        }
    }

    public function testProjectCreationIsLimitedTo30PerHourForAUser(): void
    {
        $client = self::createApiClient();
        $client->loginUser(self::createProjectWithMembers()['non-member']);
        for ($project = 1; $project <= 30; ++$project) {
            self::createProject($client, "Project $project");
        }

        $response = self::requestJson($client, 'POST', '/api/projects', ['name' => 'Project 31']);
        self::assertProblem($response, 429, 'too_many_projects');
        self::assertRetryAfter($response, 3600);
    }

    public function testNamesAndDescriptionsRejectControlCharacters(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['owner']);
        $path = '/api/projects/'.$fixture['project']->getId();

        // PostgreSQL would cut "a\0b" down to "a"; a line break in a one-line name only garbles it.
        foreach (["a\0b", "a\nb", "a\tb", "a\u{1B}[31mb", "a\u{85}b"] as $name) {
            self::assertControlCharacterError(self::requestJson($client, 'POST', '/api/projects', ['name' => $name]), 'name');
            self::assertControlCharacterError(self::requestJson($client, 'PATCH', $path, ['name' => $name]), 'name');
        }
        self::assertControlCharacterError(self::requestJson($client, 'PATCH', $path, ['description' => "a\0b"]), 'description');

        // Descriptions are multi-line.
        $described = self::requestJson($client, 'PATCH', $path, ['description' => "Line 1\r\nLine 2\n\tindented"]);
        self::assertSame("Line 1\r\nLine 2\n\tindented", self::json($described)['description']);
    }

    public function testAProjectOfOthersLooksExactlyLikeAProjectThatDoesNotExist(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['non-member']);

        $notAMember = self::requestJson($client, 'GET', '/api/projects/'.$fixture['project']->getId());
        $missing = self::requestJson($client, 'GET', '/api/projects/01a1a803-d231-7613-8d09-90acccd35cc6');

        self::assertProblem($notAMember, 404, 'not_found');
        self::assertSame($missing->getContent(), $notAMember->getContent());
        self::assertProblem(self::requestJson($client, 'GET', '/api/projects/not-a-uuid'), 404, 'not_found');
    }

    public function testTheOwnerRenamesAndDescribesTheProject(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['owner']);
        $path = '/api/projects/'.$fixture['project']->getId();

        $renamed = self::json(self::requestJson($client, 'PATCH', $path, ['name' => 'Renamed']));
        self::assertSame(['Renamed', ''], [$renamed['name'], $renamed['description']]);

        $described = self::json(self::requestJson($client, 'PATCH', $path, ['description' => 'Now with a description']));
        self::assertSame(['Renamed', 'Now with a description'], [$described['name'], $described['description']]);

        self::assertProblem(self::requestJson($client, 'PATCH', $path, ['name' => '  ']), 422, 'validation_failed');
        self::assertProblem(self::requestJson($client, 'PATCH', $path, ['name' => str_repeat('a', 201)]), 422, 'validation_failed');
        self::assertProblem(self::requestJson($client, 'PATCH', $path, ['archivedAt' => null]), 422, 'validation_failed');
    }

    public function testAnArchivedProjectIsReadOnlyUntilTheOwnerRestoresIt(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $path = '/api/projects/'.$fixture['project']->getId();
        $client->loginUser($fixture['owner']);

        $archived = self::json(self::requestJson($client, 'POST', "$path/archive"));
        self::assertNotNull($archived['archivedAt']);

        self::assertSame(200, self::requestJson($client, 'GET', $path)->getStatusCode());
        self::assertSame(200, self::requestJson($client, 'GET', "$path/members")->getStatusCode());
        self::assertProblem(self::requestJson($client, 'PATCH', $path, ['name' => 'Renamed']), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'POST', "$path/archive"), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'POST', "$path/members", ['email' => $fixture['non-member']->getEmail(), 'role' => 'viewer']), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/members/".$fixture['viewer']->getId()), 409, 'project_archived');

        $client->loginUser($fixture['viewer']);
        self::assertProblem(self::requestJson($client, 'POST', "$path/restore"), 403, 'forbidden');

        $client->loginUser($fixture['owner']);
        self::assertNull(self::json(self::requestJson($client, 'POST', "$path/restore"))['archivedAt']);
        self::assertSame(200, self::requestJson($client, 'PATCH', $path, ['name' => 'Renamed'])->getStatusCode());
    }

    private static function createProject(KernelBrowser $client, string $name): string
    {
        $response = self::requestJson($client, 'POST', '/api/projects', ['name' => $name]);
        self::assertSame(201, $response->getStatusCode());
        $id = self::json($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    private static function assertControlCharacterError(Response $response, string $field): void
    {
        self::assertProblem($response, 422, 'validation_failed');
        self::assertSame([['field' => $field, 'code' => 'control_character']], array_map(
            static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']],
            self::json($response)['errors'],
        ));
    }

    /**
     * @return list<mixed>
     */
    private static function listedIds(KernelBrowser $client, string $uri): array
    {
        return array_column(self::json(self::requestJson($client, 'GET', $uri))['items'], 'id');
    }
}
