<?php

declare(strict_types=1);

namespace App\Tests\Datasets;

use App\Datasets\Entity\Dataset;
use App\Projects\Entity\Project;
use App\Tests\ApiTestCase;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class DatasetTest extends ApiTestCase
{
    public function testTheOwnerImportsACsvAndEveryMemberReadsIt(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];

        $created = self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'support-cases.csv', ['name' => '  Support cases  ']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $dataset = self::json($created);
        self::assertSame(['id', 'name', 'createdAt', 'versions'], array_keys($dataset));
        self::assertSame('Support cases', $dataset['name']);
        $version = $dataset['versions'][0];
        self::assertSame(
            [1, 3, 'support-cases.csv', hash_file('sha256', self::fixturePath('valid.csv'))],
            [$version['number'], $version['caseCount'], $version['sourceFilename'], $version['sha256']],
        );

        $client->loginUser($fixture['viewer']);
        $list = self::json(self::requestJson($client, 'GET', "$path/datasets"));
        self::assertSame(1, $list['total']);
        self::assertSame($version, $list['items'][0]['latestVersion']);
        self::assertSame($dataset, self::json(self::requestJson($client, 'GET', "$path/datasets/{$dataset['id']}")));

        $response = self::requestJson($client, 'GET', "$path/dataset-versions/{$version['id']}/cases");
        $cases = self::json($response);
        self::assertSame(3, $cases['total']);
        self::assertSame(['greeting', 'refund-1', 'multi'], array_column($cases['items'], 'caseKey'));
        self::assertSame(['id', 'caseKey', 'input', 'reference', 'tags', 'metadata'], array_keys($cases['items'][0]));
        self::assertSame(['Say hello', 'Hello!', ['smalltalk', 'short'], ['lang' => 'en']], [$cases['items'][0]['input'], $cases['items'][0]['reference'], $cases['items'][0]['tags'], $cases['items'][0]['metadata']]);
        self::assertSame("I want a refund.\nMy order is late, and I am \"angry\".", $cases['items'][1]['input']);
        self::assertNull($cases['items'][1]['reference']);
        self::assertStringContainsString('"metadata":{}', (string) $response->getContent(), 'Empty metadata stays a JSON object');
    }

    public function testCasesAreFilteredByExactTagAndByText(): void
    {
        [$client, , $path] = self::signedInOwner();
        $version = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']))['versions'][0]['id'];
        $keys = static fn (string $query): array => array_column(self::json(self::requestJson($client, 'GET', "$path/dataset-versions/$version/cases$query"))['items'], 'caseKey');

        self::assertSame(['refund-1'], $keys('?tag=billing'));
        self::assertSame([], $keys('?tag=bill'));
        self::assertSame(['refund-1'], $keys('?q=REFUND'), 'case-insensitive, in the input');
        self::assertSame(['multi'], $keys('?q=line%202'), 'in the reference');
        self::assertSame(['greeting'], $keys('?q=greet'), 'in the case key');
        self::assertSame([], $keys('?q=%25'), '% is a literal character');
        self::assertSame(['refund-1'], $keys('?tag=billing&q=angry'));
        self::assertSame(['refund-1'], $keys('?perPage=1&page=2'));

        self::assertProblem(self::requestJson($client, 'GET', "$path/dataset-versions/$version/cases?tag=".str_repeat('x', 51)), 422, 'validation_failed');
        self::assertProblem(self::requestJson($client, 'GET', "$path/dataset-versions/$version/cases?page=10001"), 422, 'validation_failed');
    }

    public function testANewVersionLeavesTheEarlierOnesAsTheyWere(): void
    {
        [$client, , $path] = self::signedInOwner();
        $dataset = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']));
        $first = $dataset['versions'][0];

        $second = self::upload($client, "$path/datasets/{$dataset['id']}/versions", self::fixturePath('valid.json'), 'cases.json');
        self::assertSame(201, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame([2, 3, 'cases.json'], [self::json($second)['number'], self::json($second)['caseCount'], self::json($second)['sourceFilename']]);

        $shown = self::json(self::requestJson($client, 'GET', "$path/datasets/{$dataset['id']}"));
        self::assertSame([2, 1], array_column($shown['versions'], 'number'));
        self::assertSame($first, $shown['versions'][1]);
        self::assertSame(2, self::json(self::requestJson($client, 'GET', "$path/datasets"))['items'][0]['latestVersion']['number']);
        self::assertSame(['greeting', 'refund-1', 'multi'], array_column(self::json(self::requestJson($client, 'GET', "$path/dataset-versions/{$first['id']}/cases"))['items'], 'caseKey'));
        self::assertSame(['greeting', '42', 'nested'], array_column(self::json(self::requestJson($client, 'GET', "$path/dataset-versions/".self::json($second)['id'].'/cases'))['items'], 'caseKey'));
    }

    /** MVP acceptance criterion 3: an invalid file is rejected with line-level errors and nothing is stored. */
    public function testAnInvalidFileIsRejectedLineByLineAndNothingIsStored(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        $csv = "id,input,tags\na,First,x\nb,Second,y\nc,Third,z\nd,,w\n";

        $response = self::uploadContent($client, "$path/datasets", $csv, 'cases.csv', ['name' => 'Support']);

        self::assertProblem($response, 422, 'import_rejected');
        $problem = self::json($response);
        self::assertSame([1, 1, ['required' => 1], false], [$problem['errorCount'], $problem['affectedLines'], $problem['errorCountsByCode'], $problem['truncated']]);
        self::assertSame([['line' => 5, 'field' => 'input', 'code' => 'required', 'message' => 'The input is empty. Every case needs the prompt text in "input".', 'excerpt' => '']], $problem['errors']);
        self::assertSame([0, 0, 0], self::storedRows($project));
        self::assertSame(0, self::json(self::requestJson($client, 'GET', "$path/datasets"))['total']);
    }

    public function testARejectedVersionLeavesTheDatasetAsItWas(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        $dataset = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']));

        $response = self::uploadContent($client, "$path/datasets/{$dataset['id']}/versions", '[{"input": "Fine"}, {"input": "Fine too"}, {"input": 3}]', 'cases.json');

        self::assertProblem($response, 422, 'import_rejected');
        self::assertSame([['item' => 3, 'field' => 'input', 'code' => 'invalid_type']], array_map(
            static fn (array $error): array => array_slice($error, 0, 3),
            self::json($response)['errors'],
        ));
        self::assertSame([1, 1, 3], self::storedRows($project));
    }

    public function testDatasetNamesAreUniqueInAProjectAndValidated(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        self::assertSame(201, self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support'])->getStatusCode());

        // Ignoring case, and the index decides too (a name the check missed would get name_taken from the catch).
        foreach ([' Support ', 'SUPPORT'] as $name) {
            $taken = self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => $name]);
            self::assertProblem($taken, 422, 'validation_failed');
            self::assertSame('name_taken', self::json($taken)['errors'][0]['code']);
        }
        self::assertSame([1, 1, 3], self::storedRows($project));
        // The database holds it too, for datasets, rubrics and evaluations.
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame(3, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM pg_indexes WHERE indexname IN ('dataset_project_lower_name_key', 'rubric_project_lower_name_key', 'evaluation_project_lower_name_key')
            AND indexdef LIKE 'CREATE UNIQUE INDEX % (project_id, lower((name)::text))'",
        ));

        foreach (['   ' => 'is_blank', str_repeat('a', 201) => 'too_long', "Two\nlines" => 'control_character'] as $name => $code) {
            $response = self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => (string) $name]);
            self::assertProblem($response, 422, 'validation_failed');
            self::assertSame([['field' => 'name', 'code' => $code]], array_map(static fn (array $error): array => array_slice($error, 0, 2), self::json($response)['errors']));
        }
        $unknown = self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Other', 'description' => 'x']);
        self::assertSame('unknown_field', self::json($unknown)['errors'][0]['code']);

        // The same name in another project is fine.
        $other = self::createProjectWithMembers('Other project');
        $client->loginUser($other['owner']);
        self::assertSame(201, self::upload($client, '/api/projects/'.$other['project']->getId().'/datasets', self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support'])->getStatusCode());
    }

    /** Two new datasets with one name at once: the database's unique index decides, and the loser gets name_taken. */
    public function testAConcurrentImportWithTheSameNameGetsNameTaken(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        // Just before this import saves its dataset, another one with the same name commits.
        $entityManager->getEventManager()->addEventListener(Events::prePersist, new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                $dataset = $args->getObject();
                if ($dataset instanceof Dataset) {
                    $args->getObjectManager()->getConnection()->insert('dataset', [
                        'id' => Uuid::v7()->toRfc4122(), 'project_id' => $dataset->getProject()->getId()->toRfc4122(),
                        'name' => $dataset->getName(), 'created_at' => '2026-11-05 10:00:00+00',
                    ]);
                }
            }
        });

        $response = self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']);

        self::assertProblem($response, 422, 'validation_failed');
        self::assertSame('name_taken', self::json($response)['errors'][0]['code']);
        self::assertSame([0, 0, 0], self::storedRows($fixture['project']));
    }

    public function testImportsNeedAMultipartRequestWithACsvOrJsonFile(): void
    {
        [$client, , $path] = self::signedInOwner();

        self::assertProblem(self::requestJson($client, 'POST', "$path/datasets", ['name' => 'Support']), 415, 'unsupported_media_type');

        $client->request('POST', "$path/datasets", ['name' => 'Support'], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=scorewright']);
        self::assertProblem($client->getResponse(), 422, 'validation_failed');
        self::assertSame('file', self::json($client->getResponse())['errors'][0]['field']);

        $text = self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.txt', ['name' => 'Support']);
        self::assertProblem($text, 422, 'import_rejected');
        self::assertSame([null, 'unsupported_file_type'], [self::json($text)['errors'][0]['line'], self::json($text)['errors'][0]['code']]);

        $dataset = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']));
        self::assertProblem(self::requestJson($client, 'POST', "$path/datasets/{$dataset['id']}/versions", []), 415, 'unsupported_media_type');
        $extra = self::upload($client, "$path/datasets/{$dataset['id']}/versions", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'x']);
        self::assertProblem($extra, 422, 'validation_failed');
        self::assertSame('unknown_field', self::json($extra)['errors'][0]['code']);
    }

    public function testAFileOverTheLimitIsRefusedBeforeItIsRead(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        $limit = self::getContainer()->getParameter('dataset_import.max_file_bytes');
        self::assertSame(5 * 1024 * 1024, $limit);

        // Not even a valid file: read, it would be rejected as an import; at the limit, it is.
        self::assertProblem(self::uploadContent($client, "$path/datasets", str_repeat("\xFF", $limit + 1), 'cases.csv', ['name' => 'Support']), 413, 'payload_too_large');
        self::assertProblem(self::uploadContent($client, "$path/datasets", str_repeat("\xFF", $limit), 'cases.csv', ['name' => 'Support']), 422, 'import_rejected');
        self::assertSame([0, 0, 0], self::storedRows($project));
    }

    public function testDeletingADatasetRemovesItsVersionsAndCases(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        $dataset = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']));
        self::upload($client, "$path/datasets/{$dataset['id']}/versions", self::fixturePath('valid.json'), 'cases.json');
        self::assertSame([1, 2, 6], self::storedRows($project));

        $client->request('DELETE', "$path/datasets/{$dataset['id']}");
        self::assertResponseStatusCodeSame(204);
        self::assertSame([0, 0, 0], self::storedRows($project));
        self::assertProblem(self::requestJson($client, 'GET', "$path/datasets/{$dataset['id']}"), 404, 'not_found');
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/datasets/{$dataset['id']}"), 404, 'not_found');
    }

    public function testADatasetOfAnotherProjectLooksExactlyLikeOneThatDoesNotExist(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        $theirs = self::createDataset(self::createProjectWithMembers('Theirs')['project']);
        $datasetId = $theirs->getDataset()->getId()->toRfc4122();
        $missing = '01a1a803-d231-7613-8d09-90acccd35cc6';

        foreach ([
            ['GET', "$path/datasets/%s"],
            ['DELETE', "$path/datasets/%s"],
            ['GET', "$path/dataset-versions/%s/cases"],
        ] as [$method, $uri]) {
            $id = str_contains($uri, 'versions') ? $theirs->getId()->toRfc4122() : $datasetId;
            $response = self::requestJson($client, $method, \sprintf($uri, $id));
            self::assertProblem($response, 404, 'not_found');
            self::assertSame(self::requestJson($client, $method, \sprintf($uri, $missing))->getContent(), $response->getContent());
        }
        self::assertProblem(self::upload($client, "$path/datasets/$datasetId/versions", self::fixturePath('valid.csv'), 'cases.csv'), 404, 'not_found');
        self::assertSame([1, 1, 2], self::storedRows($theirs->getDataset()->getProject()));
        self::assertSame([0, 0, 0], self::storedRows($project));
    }

    public function testAnArchivedProjectKeepsItsDatasetsReadOnly(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $project = $fixture['project'];
        $version = self::createDataset($project);
        $datasetId = $version->getDataset()->getId()->toRfc4122();
        self::requestJson($client, 'POST', "$path/archive");

        self::assertProblem(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'New']), 409, 'project_archived');
        self::assertProblem(self::upload($client, "$path/datasets/$datasetId/versions", self::fixturePath('valid.csv'), 'cases.csv'), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/datasets/$datasetId"), 409, 'project_archived');
        self::assertSame(200, self::requestJson($client, 'GET', "$path/datasets")->getStatusCode());
        self::assertSame(200, self::requestJson($client, 'GET', "$path/datasets/$datasetId")->getStatusCode());
        self::assertSame(2, self::json(self::requestJson($client, 'GET', "$path/dataset-versions/{$version->getId()}/cases"))['total']);
    }

    public function testOnlyTheCleanFileNameAndTheHashAreKept(): void
    {
        [$client, , $path] = self::signedInOwner();

        $dataset = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), "C:\\Users\\ana\\evil\x07name.csv", ['name' => 'Support']));
        self::assertSame('evilname.csv', $dataset['versions'][0]['sourceFilename']);

        $long = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), str_repeat('a', 300).'.csv', ['name' => 'Long']));
        self::assertSame(str_repeat('a', 255), $long['versions'][0]['sourceFilename']);
    }

    public function testExcerptsAreNeverLogged(): void
    {
        [$client, , $path] = self::signedInOwner();
        $client->disableReboot();

        $response = self::uploadContent($client, "$path/datasets", "id,input\nsecret-case-key-7f3a,One\nsecret-case-key-7f3a,Two\n", 'cases.csv', ['name' => 'Support']);

        self::assertProblem($response, 422, 'import_rejected');
        self::assertStringContainsString('secret-case-key-7f3a', (string) $response->getContent(), 'The excerpt is in the response');
        self::assertStringNotContainsString('secret-case-key-7f3a', self::loggedText($client, 'dataset_create'));
    }

    /**
     * The client signed in as the owner of a new project, the project's members, and the project's path.
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

    /**
     * @return array{int, int, int} the datasets, versions and cases stored for the project
     */
    private static function storedRows(Project $project): array
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $row = $connection->fetchNumeric(
            'SELECT (SELECT COUNT(*) FROM dataset d WHERE d.project_id = :project),
                (SELECT COUNT(*) FROM dataset_version v JOIN dataset d ON d.id = v.dataset_id WHERE d.project_id = :project),
                (SELECT COUNT(*) FROM test_case c JOIN dataset_version v ON v.id = c.dataset_version_id JOIN dataset d ON d.id = v.dataset_id WHERE d.project_id = :project)',
            ['project' => $project->getId()->toRfc4122()],
        );
        self::assertIsArray($row);

        return [(int) $row[0], (int) $row[1], (int) $row[2]];
    }
}
