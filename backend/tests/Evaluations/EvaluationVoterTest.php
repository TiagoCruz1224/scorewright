<?php

declare(strict_types=1);

namespace App\Tests\Evaluations;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Evaluations\Entity\Evaluation;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Security\EvaluationAccess;
use App\Evaluations\Security\EvaluationVoter;
use App\Projects\Entity\Project;
use App\Projects\Entity\ProjectRole;
use App\Projects\ProjectAccess;
use App\Users\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Architecture §6: who scores, and who reads the results of a blind evaluation (the result routes come in slice 12). */
final class EvaluationVoterTest extends TestCase
{
    #[DataProvider('scorers')]
    public function testOwnersAndEvaluatorsScore(ProjectRole $role, int $expected): void
    {
        self::assertSame($expected, new EvaluationVoter()->vote(new NullToken(), self::access($role), [EvaluationVoter::SCORE]));
    }

    /**
     * @return iterable<string, array{ProjectRole, int}>
     */
    public static function scorers(): iterable
    {
        yield 'owner' => [ProjectRole::Owner, VoterInterface::ACCESS_GRANTED];
        yield 'evaluator' => [ProjectRole::Evaluator, VoterInterface::ACCESS_GRANTED];
        yield 'viewer' => [ProjectRole::Viewer, VoterInterface::ACCESS_DENIED];
    }

    #[DataProvider('resultReaders')]
    public function testAnEvaluatorReadsTheResultsOfABlindEvaluationOnlyOnceItIsClosed(ProjectRole $role, bool $blind, bool $closed, int $expected): void
    {
        $evaluation = self::evaluation($blind);
        if ($closed) {
            $evaluation->close();
        }

        self::assertSame($expected, new EvaluationVoter()->vote(new NullToken(), new EvaluationAccess(self::access($role), $evaluation), [EvaluationVoter::READ_RESULTS]));
    }

    /**
     * @return iterable<string, array{ProjectRole, bool, bool, int}>
     */
    public static function resultReaders(): iterable
    {
        foreach ([ProjectRole::Owner, ProjectRole::Evaluator, ProjectRole::Viewer] as $role) {
            foreach ([false, true] as $blind) {
                foreach ([false, true] as $closed) {
                    $hidden = ProjectRole::Evaluator === $role && $blind && !$closed;
                    yield \sprintf('%s, %s, %s', $role->value, $blind ? 'blind' : 'not blind', $closed ? 'closed' : 'open') => [$role, $blind, $closed, $hidden ? VoterInterface::ACCESS_DENIED : VoterInterface::ACCESS_GRANTED];
                }
            }
        }
    }

    public function testItAbstainsOnAnythingElse(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, new EvaluationVoter()->vote(new NullToken(), self::access(ProjectRole::Owner), [EvaluationVoter::READ_RESULTS]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, new EvaluationVoter()->vote(new NullToken(), self::access(ProjectRole::Owner), ['project.read']));
    }

    private static function access(ProjectRole $role): ProjectAccess
    {
        return new ProjectAccess(new Project('Project'), new User('someone@example.com', 'Someone'), $role);
    }

    private static function evaluation(bool $blind): Evaluation
    {
        $project = new Project('Project');
        $version = new DatasetVersion(new Dataset($project, 'Dataset'), 1, 1, 'cases.csv', str_repeat('0', 64));

        return new Evaluation($project, $version, new Rubric($project, 'Rubric'), 'Evaluation', $blind);
    }
}
