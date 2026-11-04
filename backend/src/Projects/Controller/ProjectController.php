<?php

declare(strict_types=1);

namespace App\Projects\Controller;

use App\Projects\Dto\CreateProjectRequest;
use App\Projects\Dto\ProjectListQuery;
use App\Projects\Dto\UpdateProjectRequest;
use App\Projects\Entity\Project;
use App\Projects\Entity\ProjectMember;
use App\Projects\Entity\ProjectRole;
use App\Projects\ProjectAccess;
use App\Projects\Repository\ProjectMemberRepository;
use App\Projects\Security\ProjectVoter;
use App\Users\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** {projectId} routes get their ProjectAccess from ProjectAccessListener: non-members never reach these methods. */
final class ProjectController
{
    public function __construct(
        private readonly ProjectMemberRepository $members,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/projects', name: 'project_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, #[MapQueryString] ProjectListQuery $query = new ProjectListQuery()): JsonResponse
    {
        $page = $this->members->findPageForUser($user->getId(), $query->archived, $query->page, $query->perPage);

        return new JsonResponse([
            'items' => array_map(static fn (ProjectMember $member): array => self::projectJson($member->getProject(), $member->getRole()), $page['items']),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'total' => $page['total'],
        ]);
    }

    /** Any signed-in user can create a project and becomes its owner. */
    #[Route('/api/projects', name: 'project_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateProjectRequest $request): JsonResponse
    {
        $project = new Project($request->name, $request->description);
        $this->entityManager->persist($project);
        $this->entityManager->persist(new ProjectMember($project, $user, ProjectRole::Owner));
        $this->entityManager->flush();

        return new JsonResponse(self::projectJson($project, ProjectRole::Owner), Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}', name: 'project_show', methods: ['GET'], requirements: ['projectId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess): JsonResponse
    {
        return new JsonResponse(self::projectJson($projectAccess->project, $projectAccess->role));
    }

    #[Route('/api/projects/{projectId}', name: 'project_update', methods: ['PATCH'], requirements: ['projectId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function update(ProjectAccess $projectAccess, #[MapRequestPayload] UpdateProjectRequest $request): JsonResponse
    {
        if (null !== $request->name) {
            $projectAccess->project->rename($request->name);
        }
        if (null !== $request->description) {
            $projectAccess->project->describe($request->description);
        }
        $this->entityManager->flush();

        return new JsonResponse(self::projectJson($projectAccess->project, $projectAccess->role));
    }

    #[Route('/api/projects/{projectId}/archive', name: 'project_archive', methods: ['POST'], requirements: ['projectId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function archive(ProjectAccess $projectAccess): JsonResponse
    {
        $projectAccess->project->archive();
        $this->entityManager->flush();

        return new JsonResponse(self::projectJson($projectAccess->project, $projectAccess->role));
    }

    #[Route('/api/projects/{projectId}/restore', name: 'project_restore', methods: ['POST'], requirements: ['projectId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function restore(ProjectAccess $projectAccess): JsonResponse
    {
        $projectAccess->project->restore();
        $this->entityManager->flush();

        return new JsonResponse(self::projectJson($projectAccess->project, $projectAccess->role));
    }

    /**
     * @return array{id: string, name: string, description: string, archivedAt: string|null, role: string}
     */
    private static function projectJson(Project $project, ProjectRole $role): array
    {
        return [
            'id' => $project->getId()->toRfc4122(),
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'archivedAt' => $project->getArchivedAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'role' => $role->value,
        ];
    }
}
