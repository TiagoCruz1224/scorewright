<?php

declare(strict_types=1);

namespace App\Evaluations\Security;

use App\Projects\Entity\ProjectRole;
use App\Projects\ProjectAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The evaluation rules on top of ProjectVoter (architecture §6):
 * - SCORE (subject: ProjectAccess): score, comment and read the items to score, as owner or evaluator. A closed
 *   evaluation is a state, not a permission: the scoring routes answer it with 409 evaluation_closed.
 * - READ_RESULTS (subject: EvaluationAccess): the results of slice 12. Owners and viewers always read them; an
 *   evaluator only once the evaluation is not blind or is closed, so nothing an evaluator reads ties an answer to its
 *   run while a blind evaluation is open.
 *
 * @extends Voter<string, ProjectAccess|EvaluationAccess>
 */
final class EvaluationVoter extends Voter
{
    public const SCORE = 'evaluation.score';
    public const READ_RESULTS = 'evaluation.read_results';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::SCORE === $attribute && $subject instanceof ProjectAccess)
            || (self::READ_RESULTS === $attribute && $subject instanceof EvaluationAccess);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($subject instanceof ProjectAccess) {
            return \in_array($subject->role, [ProjectRole::Owner, ProjectRole::Evaluator], true);
        }

        $evaluation = $subject->evaluation;

        return ProjectRole::Evaluator !== $subject->projectAccess->role || !$evaluation->isBlind() || $evaluation->isClosed();
    }
}
