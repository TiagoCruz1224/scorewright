<?php

declare(strict_types=1);

namespace App\Tests\Evaluations;

use App\Datasets\Entity\Answer;
use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Datasets\Entity\TestCase;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\Rubric;
use App\Projects\Entity\Project;
use App\Tests\ApiTestCase;
use App\Users\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

final class EvaluationTest extends ApiTestCase
{
    public function testTheOwnerCreatesAnEvaluationAndEveryMemberReadsIt(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 3);
        $baseline = self::runOf($version, 'baseline');
        $candidate = self::runOf($version, 'candidate', [1, 3]);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5, CriterionKind::PassFail, CriterionKind::Automatic);

        $created = self::create($client, $path, $version, [$baseline, $candidate], $rubric, ['name' => ' Weekly check ', 'blind' => false]);

        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $evaluation = self::json($created);
        self::assertSame(['id', 'name', 'status', 'blind', 'datasetVersion', 'rubric', 'progress', 'createdAt', 'closedAt', 'runs', 'checkJob'], array_keys($evaluation));
        self::assertSame(['Weekly check', 'open', false, null], [$evaluation['name'], $evaluation['status'], $evaluation['blind'], $evaluation['closedAt']]);
        self::assertSame(['id' => $rubric->getId()->toRfc4122(), 'name' => 'Quality'], $evaluation['rubric']);
        self::assertSame([
            ['letter' => 'A', 'id' => $baseline->getId()->toRfc4122(), 'label' => 'baseline'],
            ['letter' => 'B', 'id' => $candidate->getId()->toRfc4122(), 'label' => 'candidate'],
        ], $evaluation['runs']);
        // 5 items (3 + 2 answers) × 2 manual criteria; the automatic one waits for its check job (slice 10).
        self::assertSame(['scored' => 0, 'total' => 10], $evaluation['progress']);
        self::assertSame(['status' => 'queued', 'processed' => 0, 'total' => 5, 'errorCode' => null], $evaluation['checkJob']);

        $client->loginUser($fixture['viewer']);
        self::assertSame($evaluation, self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}")));
        $list = self::json(self::requestJson($client, 'GET', "$path/evaluations"));
        self::assertSame([array_slice($evaluation, 0, 9)], $list['items']);
    }

    /** Architecture §11, slice 9: the lock end to end. */
    public function testCreatingAnEvaluationLocksItsRubric(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        self::assertSame(201, self::create($client, $path, $version, [self::runOf($version, 'baseline')], $rubric)->getStatusCode());

        $edit = ['name' => 'Quality', 'criteria' => [['kind' => 'pass_fail', 'name' => 'Correct', 'guidance' => 'Pass when right.']]];
        self::assertProblem(self::requestJson($client, 'PUT', "$path/rubrics/{$rubric->getId()}", $edit), 409, 'rubric_locked');
        self::assertNotNull(self::json(self::requestJson($client, 'GET', "$path/rubrics/{$rubric->getId()}"))['lockedAt']);
        self::assertSame(201, self::requestJson($client, 'POST', "$path/rubrics/{$rubric->getId()}/duplicate")->getStatusCode());
    }

    public function testThereIsNoCheckJobWithoutAutomaticCriteria(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);

        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], self::rubric($fixture['project'], 'Manual', CriterionKind::PassFail)));

        self::assertNull($evaluation['checkJob']);
        self::assertTrue($evaluation['blind'], 'Blind mode is on unless it is turned off');
    }

    public function testTheRequestIsChecked(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);
        $run = self::runOf($version, 'baseline');
        $otherVersionRun = self::runOf(self::versionWithCases($fixture['project'], 2, 'Other'), 'other');
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        $theirs = self::createProjectWithMembers('Theirs')['project'];
        $theirVersion = self::versionWithCases($theirs, 2);
        $theirRun = self::runOf($theirVersion, 'theirs');
        $theirRubric = self::rubric($theirs, 'Theirs', CriterionKind::PassFail);
        $errors = static fn (array $fields): array => self::errorFieldsAndCodes(self::create($client, $path, $version, [$run], $rubric, $fields));

        self::assertSame([['field' => 'runs', 'code' => 'too_few']], $errors(['runs' => []]));
        self::assertSame([['field' => 'runs', 'code' => 'too_many']], $errors(['runs' => array_map(static fn (): string => Uuid::v4()->toRfc4122(), range(1, 6))]));
        self::assertSame([['field' => 'runs[1]', 'code' => 'other_dataset_version']], $errors(['runs' => [$run->getId()->toRfc4122(), $otherVersionRun->getId()->toRfc4122()]]));
        self::assertSame([['field' => 'runs[1]', 'code' => 'duplicate_run']], $errors(['runs' => [$run->getId()->toRfc4122(), $run->getId()->toRfc4122()]]));
        self::assertSame([['field' => 'name', 'code' => 'is_blank']], $errors(['name' => ' ']));

        // Another project's version, run or rubric gets the same answer as an unknown or malformed id.
        $notFound = self::create($client, $path, $version, [$run], $rubric, [
            'datasetVersion' => $theirVersion->getId()->toRfc4122(),
            'runs' => [$theirRun->getId()->toRfc4122()],
            'rubric' => $theirRubric->getId()->toRfc4122(),
        ]);
        self::assertSame([['field' => 'datasetVersion', 'code' => 'not_found'], ['field' => 'runs[0]', 'code' => 'not_found'], ['field' => 'rubric', 'code' => 'not_found']], self::errorFieldsAndCodes($notFound));
        foreach ([Uuid::v4()->toRfc4122(), 'nope'] as $unknown) {
            self::assertSame($notFound->getContent(), self::create($client, $path, $version, [$run], $rubric, ['datasetVersion' => $unknown, 'runs' => [$unknown], 'rubric' => $unknown])->getContent());
        }

        self::assertSame(201, self::create($client, $path, $version, [$run], $rubric, ['name' => 'Weekly'])->getStatusCode());
        self::assertSame([['field' => 'name', 'code' => 'name_taken']], $errors(['name' => 'Weekly']));
        self::assertSame(1, self::stored('SELECT COUNT(*) FROM evaluation WHERE project_id = ?', $fixture['project']->getId()->toRfc4122()));
    }

    /** ADR 0005: grouped by case, in the order of the cases; a partial run has no item for a case it did not answer. */
    public function testItemsAreGroupedByCaseWithTheRunsInLetterOrder(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 3);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);

        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'A-run'), self::runOf($version, 'B-run', [1, 3])], $rubric, ['blind' => false]));

        self::assertSame(
            [[0, 'c1', 'A-run'], [1, 'c1', 'B-run'], [2, 'c2', 'A-run'], [3, 'c3', 'A-run'], [4, 'c3', 'B-run']],
            array_map(static fn (array $item): array => [$item['position'], $item['case_key'], $item['label']], self::storedItems($evaluation['id'])),
        );
    }

    /**
     * In blind mode the runs are shuffled within each case. With 40 cases and 2 runs, the chance that every case gets
     * the same order is 2 × 2⁻⁴⁰ (about 2 in a trillion), so this does not fail by chance.
     */
    public function testBlindModeShufflesTheRunsWithinEachCaseAndItemIdsAreRandomV4(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 40);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);

        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'A-run'), self::runOf($version, 'B-run')], $rubric, ['blind' => true]));

        $items = self::storedItems($evaluation['id']);
        $orders = [];
        foreach (array_chunk($items, 2) as [$first, $second]) {
            self::assertSame($first['case_key'], $second['case_key'], 'The two answers to a case come one after the other');
            $orders[] = $first['label'].' first';
        }
        self::assertSame(array_map(static fn (int $n): string => "c$n", range(1, 40)), array_map(static fn (array $pair): string => $pair[0]['case_key'], array_chunk($items, 2)), 'In the order of the cases');
        self::assertEqualsCanonicalizing(['A-run first', 'B-run first'], array_values(array_unique($orders)));
        foreach ($items as $item) {
            self::assertInstanceOf(UuidV4::class, Uuid::fromString($item['id']));
        }
    }

    /** Architecture §5: in blind mode no item response says which run the answer is from. */
    public function testABlindItemNeverSaysWhichRunItIsFrom(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);
        $runs = [self::runOf($version, 'secret-label-a', says: 'One'), self::runOf($version, 'secret-label-b', says: 'Two')];
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        $evaluation = self::json(self::create($client, $path, $version, $runs, $rubric, ['blind' => true]));
        $client->loginUser($fixture['evaluator']);
        $base = "$path/evaluations/{$evaluation['id']}/items";
        $secrets = array_merge(
            ['secret-label-a', 'secret-label-b'],
            array_map(static fn (Run $run): string => $run->getId()->toRfc4122(), $runs),
            array_column(self::storedItems($evaluation['id']), 'answer_id'),
        );

        $next = self::requestJson($client, 'GET', "$base/next");
        $item = self::json($next)['id'];
        $criterion = $rubric->getCriteria()[0]->getId()->toRfc4122();
        foreach ([
            $next,
            self::requestJson($client, 'GET', "$base/$item"),
            self::requestJson($client, 'PUT', "$base/$item/scores/$criterion", ['value' => 4]),
            self::requestJson($client, 'PUT', "$base/$item/comment", ['comment' => 'Too long.']),
        ] as $response) {
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, (string) $response->getContent());
            }
            self::assertSame([], array_intersect(self::keys(self::json($response)), ['run', 'runId', 'letter', 'label', 'answerId']));
            self::assertSame(['id', 'case', 'answer', 'criteria', 'scores', 'comment', 'progress'], array_keys(self::json($response)));
        }

        // Not blind: the item says the letter and the label.
        $client->loginUser($fixture['owner']);
        $open = self::json(self::create($client, $path, $version, $runs, $rubric, ['name' => 'Open', 'blind' => false]));
        $first = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$open['id']}/items/next"));
        self::assertSame(['letter' => 'A', 'label' => 'secret-label-a'], $first['run']);
    }

    public function testNextGoesThroughTheItemsThatLackAManualScore(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5, CriterionKind::PassFail, CriterionKind::Automatic);
        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], $rubric));
        $base = "$path/evaluations/{$evaluation['id']}/items";
        [$scale, $passFail] = array_map(static fn ($criterion): string => $criterion->getId()->toRfc4122(), $rubric->getCriteria());

        $first = self::json(self::requestJson($client, 'GET', "$base/next"));
        self::assertSame(['c1', 'Input 1', 'Reference 1'], array_values($first['case']));
        self::assertSame('baseline answer to c1', $first['answer']);
        self::assertSame(['scale_1_5', 'pass_fail', 'automatic'], array_column($first['criteria'], 'kind'));
        self::assertSame(['scored' => 0, 'total' => 4], $first['progress']);

        $saved = self::json(self::requestJson($client, 'PUT', "$base/{$first['id']}/scores/$scale", ['value' => 4]));
        self::assertSame(['scored' => 1, 'total' => 4], $saved['progress']);
        self::assertSame([[$scale, 4, 'scored', 'manual', 'Owner']], array_map(static fn (array $score): array => [$score['criterionId'], $score['value'], $score['outcome'], $score['source'], $score['scoredBy']['displayName']], $saved['scores']));
        self::assertSame($first['id'], self::json(self::requestJson($client, 'GET', "$base/next"))['id'], 'Still a manual score missing');

        self::requestJson($client, 'PUT', "$base/{$first['id']}/scores/$passFail", ['value' => 1]);
        $second = self::json(self::requestJson($client, 'GET', "$base/next"));
        self::assertSame('c2', $second['case']['key']);
        self::requestJson($client, 'PUT', "$base/{$second['id']}/scores/$scale", ['value' => 2]);
        self::requestJson($client, 'PUT', "$base/{$second['id']}/scores/$passFail", ['value' => 0]);

        self::assertSame(204, self::requestJson($client, 'GET', "$base/next")->getStatusCode());
        self::assertSame(['scored' => 4, 'total' => 4], self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}"))['progress']);
    }

    public function testWithoutManualCriteriaThereIsNothingToScore(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);
        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], self::rubric($fixture['project'], 'Checks', CriterionKind::Automatic)));

        self::assertSame(204, self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}/items/next")->getStatusCode());
        self::assertSame(['scored' => 0, 'total' => 0], $evaluation['progress']);
    }

    public function testAScoreIsCheckedAgainstItsCriterion(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 1);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5, CriterionKind::PassFail, CriterionKind::Automatic);
        $otherCriterion = self::rubric($fixture['project'], 'Other', CriterionKind::Scale1To5)->getCriteria()[0]->getId()->toRfc4122();
        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], $rubric));
        $item = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}/items/next"))['id'];
        [$scale, $passFail, $automatic] = array_map(static fn ($criterion): string => $criterion->getId()->toRfc4122(), $rubric->getCriteria());
        $put = static fn (string $criterion, mixed $body): Response => self::requestJson($client, 'PUT', "$path/evaluations/{$evaluation['id']}/items/$item/scores/$criterion", $body);

        foreach ([0, 6] as $value) {
            self::assertSame([['field' => 'value', 'code' => 'not_in_range']], self::errorFieldsAndCodes($put($scale, ['value' => $value])));
        }
        self::assertSame([['field' => 'value', 'code' => 'not_in_range']], self::errorFieldsAndCodes($put($passFail, ['value' => 2])));
        self::assertSame([['field' => 'criterionId', 'code' => 'not_manual']], self::errorFieldsAndCodes($put($automatic, ['value' => 1])));
        self::assertSame([['field' => 'value', 'code' => 'is_null']], self::errorFieldsAndCodes($put($scale, [])));
        self::assertSame([['field' => 'value', 'code' => 'invalid_type']], self::errorFieldsAndCodes($put($scale, ['value' => '3'])));
        self::assertProblem($put($otherCriterion, ['value' => 3]), 404, 'not_found');
        self::assertProblem(self::requestJson($client, 'PUT', "$path/evaluations/{$evaluation['id']}/items/".Uuid::v4()."/scores/$scale", ['value' => 3]), 404, 'not_found');

        self::assertSame(200, $put($scale, ['value' => 5])->getStatusCode());
        self::assertSame(200, $put($passFail, ['value' => 0])->getStatusCode());
    }

    public function testASaveIsAnUpsertAndTheLastSaveWins(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 1);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], $rubric));
        $item = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}/items/next"))['id'];
        $uri = "$path/evaluations/{$evaluation['id']}/items/$item/scores/".$rubric->getCriteria()[0]->getId();

        self::requestJson($client, 'PUT', $uri, ['value' => 3]);
        self::requestJson($client, 'PUT', $uri, ['value' => 3]);
        $client->loginUser($fixture['evaluator']);
        $saved = self::json(self::requestJson($client, 'PUT', $uri, ['value' => 5]));

        self::assertSame([[5, 'Evaluator']], array_map(static fn (array $score): array => [$score['value'], $score['scoredBy']['displayName']], $saved['scores']));
        self::assertSame(1, self::stored('SELECT COUNT(*) FROM score s JOIN evaluation_item i ON i.id = s.evaluation_item_id WHERE i.evaluation_id = ?', $evaluation['id']));
    }

    public function testOneCommentPerAnswerIndependentOfTheScores(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 1);
        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5)));
        $item = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation['id']}/items/next"))['id'];
        $uri = "$path/evaluations/{$evaluation['id']}/items/$item/comment";

        $commented = self::json(self::requestJson($client, 'PUT', $uri, ['comment' => "Polite,\nbut wrong about the date."]));
        self::assertSame(["Polite,\nbut wrong about the date.", 'Owner', []], [$commented['comment']['text'], $commented['comment']['by']['displayName'], $commented['scores']]);

        self::assertSame([['field' => 'comment', 'code' => 'too_long']], self::errorFieldsAndCodes(self::requestJson($client, 'PUT', $uri, ['comment' => str_repeat('x', 5001)])));
        self::assertSame([['field' => 'comment', 'code' => 'is_null']], self::errorFieldsAndCodes(self::requestJson($client, 'PUT', $uri, [])));
        self::assertNull(self::json(self::requestJson($client, 'PUT', $uri, ['comment' => '']))['comment']);
        self::assertSame(200, self::requestJson($client, 'PUT', $uri, ['comment' => str_repeat('x', 5000)])->getStatusCode());
    }

    public function testClosingIsFinalAndStopsScoring(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 1);
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        $evaluation = self::json(self::create($client, $path, $version, [self::runOf($version, 'baseline')], $rubric));
        $base = "$path/evaluations/{$evaluation['id']}";
        $item = self::json(self::requestJson($client, 'GET', "$base/items/next"))['id'];

        $closed = self::json(self::requestJson($client, 'POST', "$base/close"));
        self::assertSame('closed', $closed['status']);
        self::assertNotNull($closed['closedAt']);

        self::assertProblem(self::requestJson($client, 'POST', "$base/close"), 409, 'evaluation_closed');
        $client->loginUser($fixture['evaluator']);
        self::assertProblem(self::requestJson($client, 'GET', "$base/items/next"), 409, 'evaluation_closed');
        self::assertProblem(self::requestJson($client, 'GET', "$base/items/$item"), 409, 'evaluation_closed');
        self::assertProblem(self::requestJson($client, 'PUT', "$base/items/$item/scores/".$rubric->getCriteria()[0]->getId(), ['value' => 3]), 409, 'evaluation_closed');
        self::assertProblem(self::requestJson($client, 'PUT', "$base/items/$item/comment", ['comment' => 'Late']), 409, 'evaluation_closed');
        self::assertSame('closed', self::json(self::requestJson($client, 'GET', $base))['status']);
    }

    /** Architecture §4, "Deleting": not while an evaluation uses them, and fine once it is deleted. */
    public function testAnEvaluationKeepsItsRubricRunAndDatasetUntilItIsDeleted(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 2);
        $run = self::runOf($version, 'baseline');
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        $evaluation = self::createEvaluation($rubric, [$run], name: 'Weekly check');
        $item = self::json(self::requestJson($client, 'GET', "$path/evaluations/{$evaluation->getId()}/items/next"))['id'];
        self::requestJson($client, 'PUT', "$path/evaluations/{$evaluation->getId()}/items/$item/scores/".$rubric->getCriteria()[0]->getId(), ['value' => 3]);
        $deletes = ["$path/rubrics/{$rubric->getId()}", "$path/runs/{$run->getId()}", "$path/datasets/{$version->getDataset()->getId()}"];

        foreach ($deletes as $uri) {
            $refused = self::requestJson($client, 'DELETE', $uri);
            self::assertProblem($refused, 409, 'in_use');
            self::assertSame([['id' => $evaluation->getId()->toRfc4122(), 'name' => 'Weekly check']], self::json($refused)['evaluations'], $uri);
        }

        self::assertSame(204, self::requestJson($client, 'DELETE', "$path/evaluations/{$evaluation->getId()}")->getStatusCode());
        self::assertSame(0, self::stored('SELECT COUNT(*) FROM evaluation_item WHERE evaluation_id = ?', $evaluation->getId()->toRfc4122()));
        self::assertSame(0, self::stored('SELECT COUNT(*) FROM score WHERE evaluation_item_id = ?', $item));
        foreach ($deletes as $uri) {
            self::assertSame(204, self::requestJson($client, 'DELETE', $uri)->getStatusCode(), $uri);
        }
    }

    public function testAnEvaluationOfAnotherProjectLooksExactlyLikeOneThatDoesNotExist(): void
    {
        [$client, , $path] = self::signedInOwner();
        $theirs = self::createProjectWithMembers('Theirs')['project'];
        $version = self::versionWithCases($theirs, 1);
        $rubric = self::rubric($theirs, 'Theirs', CriterionKind::Scale1To5);
        $evaluation = self::createEvaluation($rubric, [self::runOf($version, 'theirs')]);
        $item = self::storedItems($evaluation->getId()->toRfc4122())[0]['id'];
        $criterion = $rubric->getCriteria()[0]->getId();

        foreach ([
            ['GET', '', null], ['POST', '/close', null], ['DELETE', '', null], ['GET', '/items/next', null], ['GET', "/items/$item", null],
            ['PUT', "/items/$item/scores/$criterion", ['value' => 3]], ['PUT', "/items/$item/comment", ['comment' => 'Hi']],
        ] as [$method, $suffix, $body]) {
            $response = self::requestJson($client, $method, "$path/evaluations/{$evaluation->getId()}$suffix", $body);
            self::assertProblem($response, 404, 'not_found');
            self::assertSame(self::requestJson($client, $method, "$path/evaluations/".Uuid::v4().$suffix, $body)->getContent(), $response->getContent());
        }
    }

    public function testAnArchivedProjectKeepsItsEvaluationsReadOnly(): void
    {
        [$client, $fixture, $path] = self::signedInOwner();
        $version = self::versionWithCases($fixture['project'], 1);
        $run = self::runOf($version, 'baseline');
        $rubric = self::rubric($fixture['project'], 'Quality', CriterionKind::Scale1To5);
        $evaluation = self::createEvaluation($rubric, [$run]);
        $item = self::storedItems($evaluation->getId()->toRfc4122())[0]['id'];
        self::requestJson($client, 'POST', "$path/archive");
        $base = "$path/evaluations/{$evaluation->getId()}";

        self::assertProblem(self::create($client, $path, $version, [$run], $rubric, ['name' => 'New']), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'POST', "$base/close"), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'DELETE', $base), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'PUT', "$base/items/$item/scores/".$rubric->getCriteria()[0]->getId(), ['value' => 3]), 409, 'project_archived');
        self::assertProblem(self::requestJson($client, 'PUT', "$base/items/$item/comment", ['comment' => 'Hi']), 409, 'project_archived');
        self::assertSame(200, self::requestJson($client, 'GET', $base)->getStatusCode());
        self::assertSame(200, self::requestJson($client, 'GET', "$base/items/next")->getStatusCode());
    }

    /**
     * The owner of a new project, signed in. Returns the client, the members and the project's path. The client's
     * first request reboots the kernel, so create the fixtures with these entities before it.
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
     * @param list<Run>            $runs
     * @param array<string, mixed> $fields replace the defaults
     */
    private static function create(KernelBrowser $client, string $path, DatasetVersion $version, array $runs, Rubric $rubric, array $fields = []): Response
    {
        return self::requestJson($client, 'POST', "$path/evaluations", $fields + [
            'name' => 'Weekly check',
            'datasetVersion' => $version->getId()->toRfc4122(),
            'runs' => array_map(static fn (Run $run): string => $run->getId()->toRfc4122(), $runs),
            'rubric' => $rubric->getId()->toRfc4122(),
        ]);
    }

    /** A dataset (version 1) with cases c1, c2… whose inputs and references are "Input N" and "Reference N". */
    private static function versionWithCases(Project $project, int $cases, string $name = 'Support'): DatasetVersion
    {
        $entityManager = self::entityManager();
        $dataset = new Dataset($project, $name);
        $version = new DatasetVersion($dataset, 1, $cases, 'cases.csv', str_repeat('0', 64));
        $entityManager->persist($dataset);
        $entityManager->persist($version);
        for ($position = 1; $position <= $cases; ++$position) {
            $entityManager->persist(new TestCase($version, $position, "c$position", "Input $position", "Reference $position"));
        }
        $entityManager->flush();

        return $version;
    }

    /**
     * A run whose answers are "<says> answer to cN", where $says is the label unless given.
     *
     * @param list<int>|null $answered the case positions it answers; all when null
     */
    private static function runOf(DatasetVersion $version, string $label, ?array $answered = null, ?string $says = null): Run
    {
        $entityManager = self::entityManager();
        $cases = array_filter(
            $entityManager->getRepository(TestCase::class)->findBy(['datasetVersion' => $version], ['position' => 'ASC']),
            static fn (TestCase $case): bool => null === $answered || \in_array($case->getPosition(), $answered, true),
        );
        $run = new Run($version->getDataset()->getProject(), $version, $label, '', \count($cases), 'run.csv', str_repeat('0', 64));
        $entityManager->persist($run);
        foreach ($cases as $case) {
            $entityManager->persist(new Answer($run, $case, ($says ?? $label)." answer to {$case->getCaseKey()}"));
        }
        $entityManager->flush();

        return $run;
    }

    /** A rubric with one criterion of each kind given, in that order: Tone (1–5), Correct (pass/fail), Refund (contains). */
    private static function rubric(Project $project, string $name, CriterionKind ...$kinds): Rubric
    {
        $rubric = new Rubric($project, $name);
        $rubric->replaceCriteria(array_map(static fn (CriterionKind $kind): CriterionData => match ($kind) {
            CriterionKind::Scale1To5 => new CriterionData($kind, 'Tone', '1: rude. 5: warm.'),
            CriterionKind::PassFail => new CriterionData($kind, 'Correct', 'Pass when the facts are right.'),
            CriterionKind::Automatic => new CriterionData($kind, 'Refund', checkType: CheckType::Contains, checkParams: ['text' => 'refund', 'ignoreCase' => true]),
        }, array_values($kinds)));
        self::entityManager()->persist($rubric);
        self::entityManager()->flush();

        return $rubric;
    }

    /**
     * @return list<array{id: string, position: int, case_key: string, label: string, answer_id: string}> by position
     */
    private static function storedItems(string $evaluationId): array
    {
        /** @var list<array{id: string, position: int, case_key: string, label: string, answer_id: string}> $rows */
        $rows = self::connection()->fetchAllAssociative(
            'SELECT i.id, i.position, c.case_key, r.label, a.id AS answer_id FROM evaluation_item i
                JOIN answer a ON a.id = i.answer_id JOIN test_case c ON c.id = a.test_case_id JOIN run r ON r.id = a.run_id
            WHERE i.evaluation_id = ? ORDER BY i.position',
            [$evaluationId],
        );

        return $rows;
    }

    private static function stored(string $countQuery, string $parameter): int
    {
        return (int) self::connection()->fetchOne($countQuery, [$parameter]);
    }

    /**
     * @param array<mixed> $json
     *
     * @return list<string> every key, at any depth
     */
    private static function keys(array $json): array
    {
        $keys = [];
        foreach ($json as $key => $value) {
            $keys[] = (string) $key;
            if (\is_array($value)) {
                array_push($keys, ...self::keys($value));
            }
        }

        return $keys;
    }

    /**
     * @return list<array{field: mixed, code: mixed}>
     */
    private static function errorFieldsAndCodes(Response $response): array
    {
        self::assertProblem($response, 422, 'validation_failed');

        return array_values(array_map(static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']], self::json($response)['errors']));
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
