<?php

declare(strict_types=1);

namespace App\Tests\Evaluations;

use App\Evaluations\Entity\Rubric;
use App\Projects\Entity\Project;
use App\Tests\ApiTestCase;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class RubricTest extends ApiTestCase
{
    private const CRITERIA = [
        ['kind' => 'scale_1_5', 'name' => 'Tone', 'guidance' => "1: rude\n5: warm and clear", 'weight' => 2],
        ['kind' => 'pass_fail', 'name' => 'Correct', 'guidance' => 'Pass when every fact is right.'],
        ['kind' => 'automatic', 'name' => 'Starts with Refund', 'checkType' => 'regex', 'checkParams' => ['pattern' => '^Refund']],
        ['kind' => 'automatic', 'name' => 'Mentions the order', 'checkType' => 'contains', 'checkParams' => ['text' => 'order', 'ignoreCase' => true]],
    ];

    public function testTheOwnerCreatesARubricAndEveryMemberReadsIt(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();

        $created = self::requestJson($client, 'POST', "$path/rubrics", ['name' => ' Support quality ', 'criteria' => self::CRITERIA]);

        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $rubric = self::json($created);
        self::assertSame(['id', 'name', 'criterionCount', 'lockedAt', 'duplicatedFrom', 'criteria'], array_keys($rubric));
        self::assertSame(['Support quality', 4, null, null], [$rubric['name'], $rubric['criterionCount'], $rubric['lockedAt'], $rubric['duplicatedFrom']]);
        self::assertSame(['id', 'kind', 'name', 'guidance', 'weight', 'checkType', 'checkParams'], array_keys($rubric['criteria'][0]));
        self::assertSame([
            ['scale_1_5', 'Tone', "1: rude\n5: warm and clear", 2, null, null], // a whole weight is a JSON integer
            ['pass_fail', 'Correct', 'Pass when every fact is right.', null, null, null],
            ['automatic', 'Starts with Refund', '', null, 'regex', ['pattern' => '^Refund', 'ignoreCase' => false]],
            ['automatic', 'Mentions the order', '', null, 'contains', ['text' => 'order', 'ignoreCase' => true]],
        ], array_map(static fn (array $criterion): array => array_values(array_slice($criterion, 1)), $rubric['criteria']));

        $client->loginUser($fixture['viewer']);
        self::assertSame($rubric, self::json(self::requestJson($client, 'GET', "$path/rubrics/{$rubric['id']}")));
        self::assertSame(
            ['items' => [array_slice($rubric, 0, 4)], 'page' => 1, 'perPage' => 25, 'total' => 1],
            self::json(self::requestJson($client, 'GET', "$path/rubrics")),
        );
    }

    public function testAPutReplacesTheNameAndTheCriteriaInTheirNewOrder(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $id = self::json(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Quality', 'criteria' => self::CRITERIA]))['id'];
        $reordered = [self::CRITERIA[3], self::CRITERIA[0], ['kind' => 'automatic', 'name' => 'Is JSON', 'checkType' => 'valid_json']];

        $updated = self::requestJson($client, 'PUT', "$path/rubrics/$id", ['name' => 'Quality v2', 'criteria' => $reordered]);

        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame(['Quality v2', 3], [self::json($updated)['name'], self::json($updated)['criterionCount']]);
        $names = static fn (Response $response): array => array_column(self::json($response)['criteria'], 'name');
        self::assertSame(['Mentions the order', 'Tone', 'Is JSON'], $names($updated));
        self::assertSame(['Mentions the order', 'Tone', 'Is JSON'], $names(self::requestJson($client, 'GET', "$path/rubrics/$id")));
        self::assertSame(['criteria' => 3, 'positions' => '0,1,2'], self::storedCriteria($fixture['project']));
        self::assertStringContainsString('"checkType":"valid_json","checkParams":{}', (string) $updated->getContent());
    }

    public function testEveryErrorComesAtThePathOfItsField(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $criteria = self::CRITERIA;
        $criteria[1]['guidance'] = '';
        $criteria[2]['checkParams'] = ['pattern' => 'Refund (\d+'];
        $criteria[3]['checkParams']['regex'] = true;

        $response = self::requestJson($client, 'POST', "$path/rubrics", ['name' => '', 'criteria' => $criteria]);

        self::assertSame([
            ['field' => 'name', 'code' => 'is_blank'],
            ['field' => 'criteria[1].guidance', 'code' => 'is_blank'],
            ['field' => 'criteria[2].checkParams.pattern', 'code' => 'invalid_regex'],
            ['field' => 'criteria[3].checkParams.regex', 'code' => 'unknown_field'],
        ], self::errorFieldsAndCodes($response));
        self::assertSame(
            'This pattern does not compile: missing closing parenthesis at offset 11. Fix it, or put a backslash before a special character to match it as text.',
            self::json($response)['errors'][2]['message'],
        );
        self::assertSame(0, self::storedCriteria($fixture['project'])['criteria']);
    }

    public function testTheBodyIsCheckedLikeAnyOther(): void
    {
        [$client, , $path] = self::signedInOwner();

        self::assertSame([['field' => 'criteria', 'code' => 'too_few']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Empty'])));
        self::assertSame([['field' => 'criteria', 'code' => 'invalid_type']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Odd', 'criteria' => ['a' => self::CRITERIA[0]]])));
        self::assertSame([['field' => 'criteria', 'code' => 'invalid_type']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Odd', 'criteria' => 'Tone'])));
        self::assertSame([['field' => 'owner', 'code' => 'unknown_field']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Odd', 'criteria' => self::CRITERIA, 'owner' => 'me'])));
        self::assertSame([['field' => 'criteria[0]', 'code' => 'invalid_type']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Odd', 'criteria' => ['Tone']])));
    }

    public function testARubricHasAtMostThirtyCriteria(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $criteria = array_map(static fn (int $n): array => ['kind' => 'pass_fail', 'name' => "Criterion $n", 'guidance' => 'Pass or fail.'], range(1, 31));

        self::assertSame([['field' => 'criteria', 'code' => 'too_many']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Long', 'criteria' => $criteria])));
        $created = self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Long', 'criteria' => array_slice($criteria, 0, 30)]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        self::assertSame(30, self::storedCriteria($fixture['project'])['criteria']);
    }

    public function testNamesAreUniqueInTheProject(): void
    {
        [$client, , $path] = self::signedInOwner();
        $first = self::json(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Quality', 'criteria' => self::CRITERIA]))['id'];
        $second = self::json(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Safety', 'criteria' => self::CRITERIA]))['id'];
        self::createRubric(self::createProjectWithMembers('Theirs')['project'], 'Tone');

        self::assertSame([['field' => 'name', 'code' => 'name_taken']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Quality ', 'criteria' => self::CRITERIA])));
        self::assertSame([['field' => 'name', 'code' => 'name_taken']], self::errorFieldsAndCodes(self::requestJson($client, 'PUT', "$path/rubrics/$second", ['name' => 'Quality', 'criteria' => self::CRITERIA])));
        self::assertSame(200, self::requestJson($client, 'PUT', "$path/rubrics/$first", ['name' => 'Quality', 'criteria' => self::CRITERIA])->getStatusCode());
        self::assertSame(201, self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Tone', 'criteria' => self::CRITERIA])->getStatusCode());
    }

    /** Two creates with one name at once: the database's unique index decides, and the loser gets name_taken. */
    public function testAConcurrentCreateWithTheSameNameGetsNameTaken(): void
    {
        [$client, , $path] = self::signedInOwner();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        // Just before this request saves its rubric, another one with the same name commits.
        $entityManager->getEventManager()->addEventListener(Events::prePersist, new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                $rubric = $args->getObject();
                if ($rubric instanceof Rubric) {
                    $args->getObjectManager()->getConnection()->insert('rubric', [
                        'id' => Uuid::v7()->toRfc4122(), 'project_id' => $rubric->getProject()->getId()->toRfc4122(), 'name' => $rubric->getName(),
                    ]);
                }
            }
        });

        $response = self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Race', 'criteria' => self::CRITERIA]);

        self::assertSame([['field' => 'name', 'code' => 'name_taken']], self::errorFieldsAndCodes($response));
    }

    public function testADuplicateCopiesTheCriteriaUnderANewName(): void
    {
        [$client, , $path] = self::signedInOwner();
        $source = self::json(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'Quality', 'criteria' => self::CRITERIA]));

        $copy = self::requestJson($client, 'POST', "$path/rubrics/{$source['id']}/duplicate");

        self::assertSame(201, $copy->getStatusCode(), (string) $copy->getContent());
        $copy = self::json($copy);
        self::assertSame(['Quality (copy)', null, ['id' => $source['id'], 'name' => 'Quality']], [$copy['name'], $copy['lockedAt'], $copy['duplicatedFrom']]);
        $withoutIds = static fn (array $rubric): array => array_map(static fn (array $criterion): array => array_slice($criterion, 1), $rubric['criteria']);
        self::assertSame($withoutIds($source), $withoutIds($copy));
        self::assertNotSame(array_column($source['criteria'], 'id'), array_column($copy['criteria'], 'id'));

        self::assertSame([['field' => 'name', 'code' => 'name_taken']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics/{$source['id']}/duplicate")));
        self::assertSame('Quality for agents', self::json(self::requestJson($client, 'POST', "$path/rubrics/{$source['id']}/duplicate", ['name' => 'Quality for agents']))['name']);
        self::assertSame([['field' => 'name', 'code' => 'is_blank']], self::errorFieldsAndCodes(self::requestJson($client, 'POST', "$path/rubrics/{$source['id']}/duplicate", ['name' => ' '])));
    }

    public function testTheDefaultCopyNameStaysWithin200Characters(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $source = self::createRubric($fixture['project'], str_repeat('r', 200));

        $copy = self::json(self::requestJson($client, 'POST', "$path/rubrics/{$source->getId()}/duplicate"));

        self::assertSame(str_repeat('r', 193).' (copy)', $copy['name']);
    }

    /** The lock itself is set by slice 9, when the first evaluation uses the rubric; here it is set directly. */
    public function testALockedRubricRefusesEditsButCanBeDuplicated(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $rubric = self::createRubric($fixture['project']);
        $rubric->lock();
        self::flush();

        self::assertProblem(self::requestJson($client, 'PUT', "$path/rubrics/{$rubric->getId()}", ['name' => 'Edited', 'criteria' => self::CRITERIA]), 409, 'rubric_locked');
        $shown = self::json(self::requestJson($client, 'GET', "$path/rubrics/{$rubric->getId()}"));
        self::assertSame(['Quality', 2], [$shown['name'], $shown['criterionCount']]);
        self::assertNotNull($shown['lockedAt']);
        self::assertSame($shown['lockedAt'], self::json(self::requestJson($client, 'GET', "$path/rubrics"))['items'][0]['lockedAt']);

        $copy = self::requestJson($client, 'POST', "$path/rubrics/{$rubric->getId()}/duplicate");
        self::assertSame(201, $copy->getStatusCode(), (string) $copy->getContent());
        self::assertNull(self::json($copy)['lockedAt']);
        self::assertSame(200, self::requestJson($client, 'PUT', '/api/projects/'.$fixture['project']->getId().'/rubrics/'.self::json($copy)['id'], ['name' => 'Edited', 'criteria' => self::CRITERIA])->getStatusCode());
    }

    public function testDeletingARubricRemovesItsCriteriaAndKeepsItsCopies(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $source = self::createRubric($fixture['project']);
        $copy = self::json(self::requestJson($client, 'POST', "$path/rubrics/{$source->getId()}/duplicate"));

        self::assertSame(204, self::requestJson($client, 'DELETE', "$path/rubrics/{$source->getId()}")->getStatusCode());

        self::assertProblem(self::requestJson($client, 'GET', "$path/rubrics/{$source->getId()}"), 404, 'not_found');
        $kept = self::json(self::requestJson($client, 'GET', "$path/rubrics/{$copy['id']}"));
        self::assertSame([2, null], [$kept['criterionCount'], $kept['duplicatedFrom']]);
        self::assertSame(2, self::storedCriteria($fixture['project'])['criteria']);
    }

    public function testARubricOfAnotherProjectLooksExactlyLikeOneThatDoesNotExist(): void
    {
        [$client, , $path] = self::signedInOwner();
        $theirs = self::createRubric(self::createProjectWithMembers('Theirs')['project'])->getId()->toRfc4122();
        $missing = '01a1a803-d231-7613-8d09-90acccd35cc6';

        foreach ([['GET', '', null], ['PUT', '', ['name' => 'Mine', 'criteria' => self::CRITERIA]], ['DELETE', '', null], ['POST', '/duplicate', null]] as [$method, $suffix, $body]) {
            $response = self::requestJson($client, $method, "$path/rubrics/$theirs$suffix", $body);
            self::assertProblem($response, 404, 'not_found');
            self::assertSame(self::requestJson($client, $method, "$path/rubrics/$missing$suffix", $body)->getContent(), $response->getContent());
        }
    }

    public function testAnArchivedProjectKeepsItsRubricsReadOnly(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $rubric = self::createRubric($fixture['project'])->getId();
        self::requestJson($client, 'POST', "$path/archive");

        self::assertProblem(self::requestJson($client, 'POST', "$path/rubrics", ['name' => 'New', 'criteria' => self::CRITERIA]), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'PUT', "$path/rubrics/$rubric", ['name' => 'New', 'criteria' => self::CRITERIA]), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'POST', "$path/rubrics/$rubric/duplicate"), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/rubrics/$rubric"), 409, 'project_archived');
        self::assertSame(200, self::requestJson($client, 'GET', "$path/rubrics/$rubric")->getStatusCode());
    }

    /**
     * The owner of a new project, signed in. Returns the client, the members and the project's path.
     *
     * @return array{KernelBrowser, array{project: Project, owner: User, evaluator: User, viewer: User, non-member: User}, string}
     */
    private static function signedInOwner(): array
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['owner']);

        return [$client, $fixture, '/api/projects/'.$fixture['project']->getId()];
    }

    private static function flush(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();
    }

    /**
     * @return list<array{field: mixed, code: mixed}>
     */
    private static function errorFieldsAndCodes(Response $response): array
    {
        self::assertProblem($response, 422, 'validation_failed');

        return array_values(array_map(static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']], self::json($response)['errors']));
    }

    /**
     * @return array{criteria: int, positions: string} the criteria stored for the project's rubrics, and their positions in order
     */
    private static function storedCriteria(Project $project): array
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $row = $connection->fetchNumeric(
            'SELECT COUNT(*), COALESCE(STRING_AGG(c.position::text, \',\' ORDER BY c.position), \'\') FROM criterion c JOIN rubric r ON r.id = c.rubric_id
                WHERE r.project_id = :project',
            ['project' => $project->getId()->toRfc4122()],
        );
        self::assertIsArray($row);

        return ['criteria' => (int) $row[0], 'positions' => (string) $row[1]];
    }
}
