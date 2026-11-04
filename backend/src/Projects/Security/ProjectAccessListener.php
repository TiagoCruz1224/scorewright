<?php

declare(strict_types=1);

namespace App\Projects\Security;

use App\Http\ApiProblem;
use App\Projects\ProjectAccess;
use App\Projects\Repository\ProjectMemberRepository;
use App\Users\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the {projectId} route parameter before anything else touches the project (architecture §6):
 * - the caller is not a member, or the project does not exist: 404, the same answer for both;
 * - the project is archived: every write answers 409 project_archived, except restore.
 *
 * Runs after the firewall (priority 8), which has already answered 401 to anonymous callers.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final class ProjectAccessListener
{
    public function __construct(
        private readonly ProjectMemberRepository $members,
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $projectId = $request->attributes->get('projectId');
        $user = $this->security->getUser();
        if (!$event->isMainRequest() || !\is_string($projectId) || !$user instanceof User) {
            return;
        }

        $member = $this->members->findInProject(Uuid::fromString($projectId), $user->getId())
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');

        $project = $member->getProject();
        if ($project->isArchived() && !$request->isMethodSafe() && 'project_restore' !== $request->attributes->get('_route')) {
            throw new ApiProblem(409, 'project_archived', 'The project is archived and read-only: restore it first');
        }

        $request->attributes->set('projectAccess', new ProjectAccess($project, $user, $member->getRole()));
    }
}
