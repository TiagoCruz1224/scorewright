<?php

declare(strict_types=1);

namespace App\Projects\Security;

use App\Projects\Entity\ProjectRole;
use App\Projects\ProjectAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Role-based project actions (architecture §6). Membership itself is already checked by ProjectAccessListener;
 * the rules that depend on an evaluation (blind mode, closed state) will live in EvaluationVoter.
 *
 * @extends Voter<string, ProjectAccess>
 */
final class ProjectVoter extends Voter
{
    /** Read project content: owner, evaluator and viewer. */
    public const READ = 'project.read';

    /** Rename, archive, restore and manage members: owner only. */
    public const MANAGE = 'project.manage';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::READ, self::MANAGE], true) && $subject instanceof ProjectAccess;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return self::READ === $attribute || ProjectRole::Owner === $subject->role;
    }
}
