<?php

declare(strict_types=1);

namespace App\Projects\Controller;

use App\Http\ApiProblem;
use App\Http\PageQuery;
use App\Projects\Dto\InviteMemberRequest;
use App\Projects\Dto\UpdateMemberRequest;
use App\Projects\Entity\ProjectMember;
use App\Projects\Entity\ProjectRole;
use App\Projects\ProjectAccess;
use App\Projects\Repository\ProjectMemberRepository;
use App\Projects\Security\ProjectVoter;
use App\Users\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/** Members of a project: owners only. Invites add users who already have an account (no email in the MVP). */
#[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
final class MemberController
{
    public function __construct(
        private readonly ProjectMemberRepository $members,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactoryInterface $projectInviteLimiter,
    ) {
    }

    #[Route('/api/projects/{projectId}/members', name: 'member_list', methods: ['GET'], requirements: ['projectId' => Requirement::UUID])]
    public function list(ProjectAccess $projectAccess, #[MapQueryString] PageQuery $query = new PageQuery()): JsonResponse
    {
        $page = $this->members->findPageInProject($projectAccess->project->getId(), $query->page, $query->perPage);

        return new JsonResponse([
            'items' => array_map(self::memberJson(...), $page['items']),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'total' => $page['total'],
        ]);
    }

    #[Route('/api/projects/{projectId}/members', name: 'member_invite', methods: ['POST'], requirements: ['projectId' => Requirement::UUID])]
    public function invite(ProjectAccess $projectAccess, #[MapRequestPayload] InviteMemberRequest $request): JsonResponse
    {
        // Every invite tells the owner whether an account exists (§9), so attempts are limited per project and owner.
        $limiter = $this->projectInviteLimiter->create($projectAccess->project->getId().':'.$projectAccess->user->getId());
        if (!$limiter->consume()->isAccepted()) {
            throw new ApiProblem(429, 'too_many_invites', 'Too many invites for this project: try again later');
        }

        $user = $this->users->loadUserByIdentifier($request->email)
            ?? throw self::emailError('no_account', 'No account uses this email. Ask the person to register first.');
        if (null !== $this->members->findInProject($projectAccess->project->getId(), $user->getId())) {
            throw self::emailError('already_member', 'This user is already a member of the project.');
        }

        $member = new ProjectMember($projectAccess->project, $user, ProjectRole::from($request->role));
        $this->entityManager->persist($member);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::emailError('already_member', 'This user is already a member of the project.'); // concurrent invite
        }

        return new JsonResponse(self::memberJson($member), Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}/members/{userId}', name: 'member_update', methods: ['PATCH'], requirements: ['projectId' => Requirement::UUID, 'userId' => Requirement::UUID])]
    public function update(ProjectAccess $projectAccess, string $userId, #[MapRequestPayload] UpdateMemberRequest $request): JsonResponse
    {
        $role = ProjectRole::from($request->role);
        $member = $this->entityManager->wrapInTransaction(function () use ($projectAccess, $userId, $role): ProjectMember {
            $member = $this->lockAndFindMember($projectAccess, $userId);
            if (ProjectRole::Owner !== $role) {
                $this->assertNotTheLastOwner($member);
            }
            $member->changeRole($role);

            return $member;
        });

        return new JsonResponse(self::memberJson($member));
    }

    #[Route('/api/projects/{projectId}/members/{userId}', name: 'member_remove', methods: ['DELETE'], requirements: ['projectId' => Requirement::UUID, 'userId' => Requirement::UUID])]
    public function remove(ProjectAccess $projectAccess, string $userId): Response
    {
        $this->entityManager->wrapInTransaction(function () use ($projectAccess, $userId): void {
            $member = $this->lockAndFindMember($projectAccess, $userId);
            $this->assertNotTheLastOwner($member);
            $this->entityManager->remove($member);
        });

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Locks the project row, so two owners cannot demote or remove each other at the same time. */
    private function lockAndFindMember(ProjectAccess $projectAccess, string $userId): ProjectMember
    {
        $this->entityManager->lock($projectAccess->project, LockMode::PESSIMISTIC_WRITE);

        return $this->members->findInProject($projectAccess->project->getId(), Uuid::fromString($userId))
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');
    }

    private function assertNotTheLastOwner(ProjectMember $member): void
    {
        if (ProjectRole::Owner === $member->getRole() && 1 === $this->members->countOwners($member->getProject()->getId())) {
            throw new ApiProblem(409, 'last_owner', 'A project needs at least one owner: make another member owner first');
        }
    }

    private static function emailError(string $code, string $message): ApiProblem
    {
        return ApiProblem::validation([['field' => 'email', 'code' => $code, 'message' => $message]]);
    }

    /**
     * Member emails are only returned here, to owners (§9).
     *
     * @return array{userId: string, email: string, displayName: string, role: string}
     */
    private static function memberJson(ProjectMember $member): array
    {
        return [
            'userId' => $member->getUser()->getId()->toRfc4122(),
            'email' => $member->getUser()->getEmail(),
            'displayName' => $member->getUser()->getDisplayName(),
            'role' => $member->getRole()->value,
        ];
    }
}
