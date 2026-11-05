<?php

declare(strict_types=1);

namespace App\Tests\Datasets;

use App\Datasets\Entity\Run;
use App\Projects\Entity\Project;
use App\Tests\ApiTestCase;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

final class RunTest extends ApiTestCase
{
    public function testTheOwnerImportsARunAndEveryMemberReadsItsMetadata(): void
    {
        [$client, $fixture, $path, $versionId] = self::projectWithDataset();

        $created = self::importRun($client, $path, $versionId, 'run-valid.csv', 'baseline-v1', ['modelDescription' => "Model X\ntemperature 0"]);

        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $run = self::json($created);
        self::assertSame(['id', 'label', 'modelDescription', 'datasetVersion', 'answerCount', 'sourceFilename', 'sha256', 'createdAt'], array_keys($run));
        self::assertSame(
            ['baseline-v1', "Model X\ntemperature 0", 3, 'run-valid.csv', hash_file('sha256', self::fixturePath('run-valid.csv'))],
            [$run['label'], $run['modelDescription'], $run['answerCount'], $run['sourceFilename'], $run['sha256']],
        );
        self::assertSame(['id' => $versionId, 'number' => 1, 'caseCount' => 3], array_slice($run['datasetVersion'], 0, 3));
        self::assertSame('Support', $run['datasetVersion']['dataset']['name']);

        $client->loginUser($fixture['viewer']);
        self::assertSame(['items' => [$run], 'page' => 1, 'perPage' => 25, 'total' => 1], self::json(self::requestJson($client, 'GET', "$path/runs")));
        self::assertSame($run, self::json(self::requestJson($client, 'GET', "$path/runs/{$run['id']}")));
    }

    public function testARunMayLeaveCasesUnansweredAndShowsItsCoverage(): void
    {
        [$client, $fixture, $path, $versionId] = self::projectWithDataset();

        $run = self::json(self::importRun($client, $path, $versionId, 'run-partial.json'));

        self::assertSame([2, 3], [$run['answerCount'], $run['datasetVersion']['caseCount']]);
        self::assertSame([1, 2], self::storedRuns($fixture['project']));
    }

    public function testRunsAreListedNewestFirstAndCanBeFilteredByVersion(): void
    {
        [$client, , $path, $first, $datasetId] = self::projectWithDataset();
        $second = self::json(self::upload($client, "$path/datasets/$datasetId/versions", self::fixturePath('valid.json'), 'cases.json'))['id'];
        self::importRun($client, $path, $first, 'run-valid.csv', 'on-v1');
        self::assertSame(201, self::uploadContent($client, "$path/runs", "id,output\n42,Forty-two\n", 'run.csv', ['datasetVersion' => $second, 'label' => 'on-v2'])->getStatusCode());

        $labels = static fn (string $query): array => array_column(self::json(self::requestJson($client, 'GET', "$path/runs$query"))['items'], 'label');
        self::assertSame(['on-v2', 'on-v1'], $labels(''));
        self::assertSame(['on-v1'], $labels("?datasetVersion=$first"));
        self::assertProblem(self::requestJson($client, 'GET', "$path/runs?datasetVersion=nope"), 422, 'validation_failed');
    }

    public function testLabelsAreUniqueInAVersionAndValidated(): void
    {
        [$client, $fixture, $path, $versionId, $datasetId] = self::projectWithDataset();
        self::importRun($client, $path, $versionId, 'run-valid.csv', 'baseline-v1');

        $taken = self::importRun($client, $path, $versionId, 'run-valid.csv', ' baseline-v1 ');
        self::assertSame([['field' => 'label', 'code' => 'label_taken']], self::errorFieldsAndCodes($taken));
        self::assertSame([1, 3], self::storedRuns($fixture['project']));

        // The same label on another version of the dataset is fine.
        $second = self::json(self::upload($client, "$path/datasets/$datasetId/versions", self::fixturePath('valid.csv'), 'cases.csv'))['id'];
        self::assertSame(201, self::importRun($client, $path, $second, 'run-valid.csv', 'baseline-v1')->getStatusCode());

        foreach (['   ' => 'is_blank', str_repeat('a', 101) => 'too_long', "two\nlines" => 'control_character'] as $label => $code) {
            self::assertSame([['field' => 'label', 'code' => $code]], self::errorFieldsAndCodes(self::importRun($client, $path, $versionId, 'run-valid.csv', (string) $label)));
        }
        self::assertSame([['field' => 'modelDescription', 'code' => 'too_long']], self::errorFieldsAndCodes(self::importRun($client, $path, $versionId, 'run-valid.csv', 'other', ['modelDescription' => str_repeat('a', 2001)])));
        self::assertSame([['field' => 'temperature', 'code' => 'unknown_field']], self::errorFieldsAndCodes(self::importRun($client, $path, $versionId, 'run-valid.csv', 'other', ['temperature' => '0'])));
    }

    /** Two imports with one label at once: the database's unique index decides, and the loser gets label_taken. */
    public function testAConcurrentImportWithTheSameLabelGetsLabelTaken(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $version = self::createDataset($fixture['project']);
        $client->loginUser($fixture['owner']);
        $client->disableReboot();
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        // Just before this import saves its run, another one with the same label commits.
        $entityManager->getEventManager()->addEventListener(Events::prePersist, new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                $run = $args->getObject();
                if ($run instanceof Run) {
                    $args->getObjectManager()->getConnection()->insert('run', [
                        'id' => Uuid::v7()->toRfc4122(), 'project_id' => $run->getProject()->getId()->toRfc4122(),
                        'dataset_version_id' => $run->getDatasetVersion()->getId()->toRfc4122(), 'label' => $run->getLabel(),
                        'model_description' => '', 'answer_count' => 0, 'source_filename' => 'other.csv', 'sha256' => str_repeat('0', 64),
                        'created_at' => '2026-11-05 10:00:00+00',
                    ]);
                }
            }
        });

        $response = self::importRun($client, '/api/projects/'.$fixture['project']->getId(), $version->getId()->toRfc4122(), 'run-for-a-b.csv', 'race');

        self::assertSame([['field' => 'label', 'code' => 'label_taken']], self::errorFieldsAndCodes($response));
    }

    public function testTheVersionMustBeOneOfTheProjectsAndAnyOtherGetsTheSameAnswer(): void
    {
        [$client, , $path] = self::projectWithDataset();
        $theirs = self::createDataset(self::createProjectWithMembers('Theirs')['project'])->getId()->toRfc4122();

        $notTheirs = self::importRun($client, $path, $theirs, 'run-for-a-b.csv');
        self::assertSame([['field' => 'datasetVersion', 'code' => 'not_found']], self::errorFieldsAndCodes($notTheirs));
        foreach (['01a1a803-d231-7613-8d09-90acccd35cc6', 'nope'] as $other) {
            self::assertSame($notTheirs->getContent(), self::importRun($client, $path, $other, 'run-for-a-b.csv')->getContent());
        }
        self::assertSame([['field' => 'datasetVersion', 'code' => 'is_blank']], self::errorFieldsAndCodes(self::importRun($client, $path, '', 'run-for-a-b.csv')));
    }

    public function testAnInvalidRunFileIsRejectedLineByLineAndNothingIsStored(): void
    {
        [$client, $fixture, $path, $versionId] = self::projectWithDataset();

        $response = self::uploadContent($client, "$path/runs", "id,output\ngreeting,Hi\nrefund-1,Sorry\nrefund-1,Sorry again\nnope,Who?\n", 'run.csv', ['datasetVersion' => $versionId, 'label' => 'bad']);

        self::assertProblem($response, 422, 'import_rejected');
        self::assertSame([[4, 'id', 'duplicate_case_key'], [5, 'id', 'unknown_case_key']], array_map(
            static fn (array $error): array => [$error['line'], $error['field'], $error['code']],
            self::json($response)['errors'],
        ));
        self::assertSame([0, 0], self::storedRuns($fixture['project']));
    }

    /**
     * Architecture §5, "Run data and blind mode": no route lists a run's answers. Every GET route, as the owner, with
     * a run whose answer holds a marker; a new route with another parameter fails here until it is added.
     */
    public function testNoRouteReturnsAnAnswer(): void
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $version = self::createDataset($fixture['project']);
        $run = self::createRun($version, output: 'answer-text-7b1e');
        $client->loginUser($fixture['owner']);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $placeholders = [
            '{projectId}' => $fixture['project']->getId()->toRfc4122(),
            '{datasetId}' => $version->getDataset()->getId()->toRfc4122(),
            '{versionId}' => $version->getId()->toRfc4122(),
            '{runId}' => $run->getId()->toRfc4122(),
            '{userId}' => $fixture['viewer']->getId()->toRfc4122(),
            '{rubricId}' => self::createRubric($fixture['project'])->getId()->toRfc4122(),
        ];

        $checked = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!\in_array('GET', $route->getMethods(), true)) {
                continue;
            }
            $path = strtr($route->getPath(), $placeholders);
            self::assertStringNotContainsString('{', $path, "Route \"$name\" has a parameter this test does not fill");
            $response = self::requestJson($client, 'GET', $path);
            self::assertSame(200, $response->getStatusCode(), "$name: ".$response->getContent());
            self::assertStringNotContainsString('answer-text-7b1e', (string) $response->getContent(), $name);
            self::assertStringNotContainsString('"output"', (string) $response->getContent(), $name);
            ++$checked;
        }
        self::assertGreaterThanOrEqual(13, $checked, 'Every GET route was checked');
    }

    public function testDeletingARunRemovesItsAnswersAndDeletingTheDatasetRemovesItsRuns(): void
    {
        [$client, $fixture, $path, $versionId, $datasetId] = self::projectWithDataset();
        $run = self::json(self::importRun($client, $path, $versionId, 'run-valid.csv'));
        self::importRun($client, $path, $versionId, 'run-partial.json', 'second');
        self::assertSame([2, 5], self::storedRuns($fixture['project']));

        $client->request('DELETE', "$path/runs/{$run['id']}");
        self::assertResponseStatusCodeSame(204);
        self::assertSame([1, 2], self::storedRuns($fixture['project']));
        self::assertProblem(self::requestJson($client, 'GET', "$path/runs/{$run['id']}"), 404, 'not_found');
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/runs/{$run['id']}"), 404, 'not_found');

        $client->request('DELETE', "$path/datasets/$datasetId");
        self::assertSame([0, 0], self::storedRuns($fixture['project']));
    }

    public function testARunOfAnotherProjectLooksExactlyLikeOneThatDoesNotExist(): void
    {
        [$client, , $path] = self::projectWithDataset();
        $theirs = self::createRun(self::createDataset(self::createProjectWithMembers('Theirs')['project']))->getId()->toRfc4122();
        $missing = '01a1a803-d231-7613-8d09-90acccd35cc6';

        foreach (['GET', 'DELETE'] as $method) {
            $response = self::requestJson($client, $method, "$path/runs/$theirs");
            self::assertProblem($response, 404, 'not_found');
            self::assertSame(self::requestJson($client, $method, "$path/runs/$missing")->getContent(), $response->getContent());
        }
    }

    public function testAnArchivedProjectKeepsItsRunsReadOnly(): void
    {
        [$client, , $path, $versionId] = self::projectWithDataset();
        $run = self::json(self::importRun($client, $path, $versionId, 'run-valid.csv'));
        self::requestJson($client, 'POST', "$path/archive");

        self::assertProblem(self::importRun($client, $path, $versionId, 'run-valid.csv', 'other'), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'DELETE', "$path/runs/{$run['id']}"), 409, 'project_archived');
        self::assertSame(1, self::json(self::requestJson($client, 'GET', "$path/runs"))['total']);
        self::assertSame(200, self::requestJson($client, 'GET', "$path/runs/{$run['id']}")->getStatusCode());
    }

    public function testTheProjectsVersionsAreListedToChooseOne(): void
    {
        [$client, , $path, $first, $datasetId] = self::projectWithDataset();
        $second = self::json(self::upload($client, "$path/datasets/$datasetId/versions", self::fixturePath('valid.json'), 'cases.json'))['id'];
        self::createDataset(self::createProjectWithMembers('Theirs')['project']);

        $versions = self::json(self::requestJson($client, 'GET', "$path/dataset-versions"));

        self::assertSame(2, $versions['total']);
        self::assertSame([[$second, 2, 'Support'], [$first, 1, 'Support']], array_map(
            static fn (array $version): array => [$version['id'], $version['number'], $version['dataset']['name']],
            $versions['items'],
        ));
    }

    /**
     * The owner of a new project signed in, with the dataset "Support" imported from valid.csv (cases greeting,
     * refund-1 and multi).
     *
     * Returns the client, the members, the project's path, the version id and the dataset id.
     *
     * @return array{KernelBrowser, array{project: Project, owner: User, evaluator: User, viewer: User, non-member: User}, string, string, string}
     */
    private static function projectWithDataset(): array
    {
        $client = self::createApiClient();
        $fixture = self::createProjectWithMembers();
        $client->loginUser($fixture['owner']);
        $path = '/api/projects/'.$fixture['project']->getId();
        $dataset = self::json(self::upload($client, "$path/datasets", self::fixturePath('valid.csv'), 'cases.csv', ['name' => 'Support']));

        return [$client, $fixture, $path, $dataset['versions'][0]['id'], $dataset['id']];
    }

    /**
     * @param array<string, string> $fields
     */
    private static function importRun(KernelBrowser $client, string $path, string $versionId, string $fixture, string $label = 'baseline-v1', array $fields = []): Response
    {
        return self::upload($client, "$path/runs", self::fixturePath($fixture), $fixture, ['datasetVersion' => $versionId, 'label' => $label] + $fields);
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
     * @return array{int, int} the runs and answers stored for the project
     */
    private static function storedRuns(Project $project): array
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $row = $connection->fetchNumeric(
            'SELECT (SELECT COUNT(*) FROM run WHERE project_id = :project),
                (SELECT COUNT(*) FROM answer a JOIN run r ON r.id = a.run_id WHERE r.project_id = :project)',
            ['project' => $project->getId()->toRfc4122()],
        );
        self::assertIsArray($row);

        return [(int) $row[0], (int) $row[1]];
    }
}
