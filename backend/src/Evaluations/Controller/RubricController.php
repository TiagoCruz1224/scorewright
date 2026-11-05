<?php

declare(strict_types=1);

namespace App\Evaluations\Controller;

use App\Evaluations\Dto\DuplicateRubricRequest;
use App\Evaluations\Dto\SaveRubricRequest;
use App\Evaluations\Entity\Criterion;
use App\Evaluations\Entity\Rubric;
use App\Evaluations\Repository\RubricRepository;
use App\Http\ApiProblem;
use App\Http\PageQuery;
use App\Projects\ProjectAccess;
use App\Projects\Security\ProjectVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Rubrics (architecture §5). Members read; owners create, edit, duplicate and delete. A locked rubric (one that an
 * evaluation uses) answers PUT with 409 rubric_locked and can still be duplicated (§4, "Immutability").
 */
final class RubricController
{
    private const PROJECT = ['projectId' => Requirement::UUID];
    private const RUBRIC = ['projectId' => Requirement::UUID, 'rubricId' => Requirement::UUID];
    private const COPY_SUFFIX = ' (copy)';

    public function __construct(
        private readonly RubricRepository $rubrics,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/projects/{projectId}/rubrics', name: 'rubric_list', methods: ['GET'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function list(ProjectAccess $projectAccess, #[MapQueryString] PageQuery $query = new PageQuery()): JsonResponse
    {
        $page = $this->rubrics->findPageInProject($projectAccess->project->getId(), $query->page, $query->perPage);

        return new JsonResponse([
            'items' => array_map(self::summaryJson(...), $page['items']),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'total' => $page['total'],
        ]);
    }

    #[Route('/api/projects/{projectId}/rubrics', name: 'rubric_create', methods: ['POST'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function create(ProjectAccess $projectAccess, #[MapRequestPayload(acceptFormat: 'json')] SaveRubricRequest $body): JsonResponse
    {
        if ($this->rubrics->nameIsTaken($projectAccess->project->getId(), $body->name)) {
            throw self::nameTaken();
        }
        $rubric = new Rubric($projectAccess->project, $body->name);
        $rubric->replaceCriteria($body->criteriaData());

        return new JsonResponse(self::rubricJson($this->save($rubric)), Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}/rubrics/{rubricId}', name: 'rubric_show', methods: ['GET'], requirements: self::RUBRIC)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $rubricId): JsonResponse
    {
        return new JsonResponse(self::rubricJson($this->findRubric($projectAccess, $rubricId)));
    }

    /** Replaces the name and every criterion. A body that is not valid answers 422 before the lock is looked at. */
    #[Route('/api/projects/{projectId}/rubrics/{rubricId}', name: 'rubric_update', methods: ['PUT'], requirements: self::RUBRIC)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function update(ProjectAccess $projectAccess, string $rubricId, #[MapRequestPayload(acceptFormat: 'json')] SaveRubricRequest $body): JsonResponse
    {
        try {
            $rubric = $this->entityManager->wrapInTransaction(function () use ($projectAccess, $rubricId, $body): Rubric {
                // FOR UPDATE: slice 9 locks the rubric in the transaction that creates its first evaluation, so an
                // edit either commits before that or sees locked_at.
                $rubric = $this->findRubric($projectAccess, $rubricId, LockMode::PESSIMISTIC_WRITE);
                if ($rubric->isLocked()) {
                    throw new ApiProblem(409, 'rubric_locked', 'The rubric is used in an evaluation and cannot change: duplicate it to change it');
                }
                if ($this->rubrics->nameIsTaken($projectAccess->project->getId(), $body->name, $rubric)) {
                    throw self::nameTaken();
                }
                $rubric->rename($body->name);
                $rubric->replaceCriteria($body->criteriaData());

                return $rubric;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken(); // renamed by a concurrent request
        }

        return new JsonResponse(self::rubricJson($rubric));
    }

    /** Deletes the rubric with its criteria (database cascade); copies of it keep their criteria. */
    #[Route('/api/projects/{projectId}/rubrics/{rubricId}', name: 'rubric_delete', methods: ['DELETE'], requirements: self::RUBRIC)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function delete(ProjectAccess $projectAccess, string $rubricId): Response
    {
        $rubric = $this->findRubric($projectAccess, $rubricId);
        // Slice 9: when an evaluation uses this rubric, answer 409 in_use here, listing those evaluations
        // (architecture §4, "Deleting").
        $this->entityManager->remove($rubric);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** An unlocked copy with the same criteria, locked or not; the body {"name"} is optional. */
    #[Route('/api/projects/{projectId}/rubrics/{rubricId}/duplicate', name: 'rubric_duplicate', methods: ['POST'], requirements: self::RUBRIC)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function duplicate(ProjectAccess $projectAccess, string $rubricId, #[MapRequestPayload(acceptFormat: 'json')] DuplicateRubricRequest $body = new DuplicateRubricRequest()): JsonResponse
    {
        $source = $this->findRubric($projectAccess, $rubricId);
        // "<name> (copy)", with the name shortened when the whole would be over 200 characters.
        $name = $body->name ?? mb_substr($source->getName(), 0, 200 - mb_strlen(self::COPY_SUFFIX)).self::COPY_SUFFIX;
        if ($this->rubrics->nameIsTaken($projectAccess->project->getId(), $name)) {
            throw self::nameTaken();
        }

        return new JsonResponse(self::rubricJson($this->save($source->duplicate($name))), Response::HTTP_CREATED);
    }

    private function save(Rubric $rubric): Rubric
    {
        try {
            $this->entityManager->persist($rubric);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken(); // created by a concurrent request
        }

        return $rubric;
    }

    private function findRubric(ProjectAccess $projectAccess, string $rubricId, ?LockMode $lockMode = null): Rubric
    {
        // The same answer as for a project you are not a member of.
        return $this->rubrics->findInProject($projectAccess->project->getId(), Uuid::fromString($rubricId), $lockMode)
            ?? throw new ApiProblem(404, 'not_found', 'Not Found');
    }

    private static function nameTaken(): ApiProblem
    {
        return ApiProblem::validation([['field' => 'name', 'code' => 'name_taken', 'message' => 'The project already has a rubric with this name. Choose another name.']]);
    }

    /**
     * @return array{id: string, name: string, criterionCount: int, lockedAt: string|null}
     */
    private static function summaryJson(Rubric $rubric): array
    {
        return [
            'id' => $rubric->getId()->toRfc4122(),
            'name' => $rubric->getName(),
            'criterionCount' => \count($rubric->getCriteria()),
            'lockedAt' => $rubric->getLockedAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rubricJson(Rubric $rubric): array
    {
        $source = $rubric->getDuplicatedFrom();

        return self::summaryJson($rubric) + [
            'duplicatedFrom' => null === $source ? null : ['id' => $source->getId()->toRfc4122(), 'name' => $source->getName()],
            'criteria' => array_map(self::criterionJson(...), $rubric->getCriteria()),
        ];
    }

    /**
     * checkType and checkParams are null for manual criteria; checkParams is an object for automatic ones.
     *
     * @return array<string, mixed>
     */
    private static function criterionJson(Criterion $criterion): array
    {
        $data = $criterion->getData();

        return [
            'id' => $criterion->getId()->toRfc4122(),
            'kind' => $data->kind->value,
            'name' => $data->name,
            'guidance' => $data->guidance,
            'weight' => $data->weight,
            'checkType' => $data->checkType?->value,
            'checkParams' => null === $data->checkParams ? null : (object) $data->checkParams,
        ];
    }
}
