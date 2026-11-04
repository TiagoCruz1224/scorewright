<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Tests\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MemberTest extends ApiTestCase
{
    public function testTheOwnerSeesTheMembersWithTheirEmails(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['owner']);

        $page = self::json(self::requestJson($client, 'GET', '/api/projects/'.$fixture['project']->getId().'/members'));

        self::assertSame(3, $page['total']);
        self::assertSame(['Evaluator', 'Owner', 'Viewer'], array_column($page['items'], 'displayName'));
        self::assertSame(['userId' => $fixture['owner']->getId()->toRfc4122(), 'email' => $fixture['owner']->getEmail(), 'displayName' => 'Owner', 'role' => 'owner'], $page['items'][1]);
    }

    public function testTheOwnerInvitesAnExistingUserByEmail(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $path = '/api/projects/'.$fixture['project']->getId();
        $client->loginUser($fixture['owner']);

        $invited = self::requestJson($client, 'POST', "$path/members", ['email' => strtoupper($fixture['non-member']->getEmail()), 'role' => 'evaluator']);
        self::assertSame(201, $invited->getStatusCode());
        self::assertSame('evaluator', self::json($invited)['role']);

        $client->loginUser($fixture['non-member']);
        self::assertSame('evaluator', self::json(self::requestJson($client, 'GET', $path))['role']);
    }

    public function testInvitesExplainWhatIsWrong(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $path = '/api/projects/'.$fixture['project']->getId().'/members';
        $client->loginUser($fixture['owner']);

        self::assertErrorCode(self::requestJson($client, 'POST', $path, ['email' => 'nobody@example.com', 'role' => 'viewer']), 'no_account');
        self::assertErrorCode(self::requestJson($client, 'POST', $path, ['email' => $fixture['viewer']->getEmail(), 'role' => 'viewer']), 'already_member');
        self::assertErrorCode(self::requestJson($client, 'POST', $path, ['email' => $fixture['non-member']->getEmail(), 'role' => 'admin']), 'no_such_choice');
    }

    public function testInvitesAreLimitedTo20PerHourForAProjectAndOwner(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $path = '/api/projects/'.$fixture['project']->getId().'/members';
        $client->loginUser($fixture['owner']);

        for ($invite = 1; $invite <= 20; ++$invite) {
            self::assertErrorCode(self::requestJson($client, 'POST', $path, ['email' => "nobody$invite@example.com", 'role' => 'viewer']), 'no_account');
        }

        self::assertProblem(self::requestJson($client, 'POST', $path, ['email' => $fixture['non-member']->getEmail(), 'role' => 'viewer']), 429, 'too_many_invites');
    }

    public function testTheLastOwnerCanBeNeitherDemotedNorRemoved(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $path = '/api/projects/'.$fixture['project']->getId().'/members/';
        $client->loginUser($fixture['owner']);
        $owner = $fixture['owner']->getId();

        self::assertProblem(self::requestJson($client, 'PATCH', $path.$owner, ['role' => 'viewer']), 409, 'last_owner');
        self::assertProblem(self::requestJson($client, 'DELETE', $path.$owner), 409, 'last_owner');

        // With a second owner, the first one can step down.
        self::assertSame(200, self::requestJson($client, 'PATCH', $path.$fixture['evaluator']->getId(), ['role' => 'owner'])->getStatusCode());
        self::assertSame('viewer', self::json(self::requestJson($client, 'PATCH', $path.$owner, ['role' => 'viewer']))['role']);
    }

    public function testRemovingAMemberEndsTheirAccess(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $path = '/api/projects/'.$fixture['project']->getId();
        $client->loginUser($fixture['owner']);

        self::assertSame(204, self::requestJson($client, 'DELETE', "$path/members/".$fixture['viewer']->getId())->getStatusCode());
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/members/".$fixture['viewer']->getId()), 404, 'not_found');

        $client->loginUser($fixture['viewer']);
        self::assertProblem(self::requestJson($client, 'GET', $path), 404, 'not_found');
    }

    private static function assertErrorCode(Response $response, string $code): void
    {
        self::assertProblem($response, 422, 'validation_failed');
        self::assertSame([$code], array_column(self::json($response)['errors'], 'code'));
    }
}
