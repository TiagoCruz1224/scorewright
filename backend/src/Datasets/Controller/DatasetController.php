<?php

declare(strict_types=1);

namespace App\Datasets\Controller;

use App\Datasets\Dto\CaseListQuery;
use App\Datasets\Dto\CreateDatasetRequest;
use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Import\DatasetImporter;
use App\Datasets\Import\ImportErrors;
use App\Datasets\Repository\DatasetRepository;
use App\Datasets\Repository\DatasetVersionRepository;
use App\Datasets\Repository\TestCaseRepository;
use App\Http\ApiProblem;
use App\Http\PageQuery;
use App\Projects\ProjectAccess;
use App\Projects\Security\ProjectVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Datasets, their versions and cases (architecture §5). Members read; owners import and delete. A dataset or version
 * of another project is a 404, like the project itself. Imports are all or nothing (ADR 0004): a rejected file
 * answers 422 import_rejected and saves nothing.
 */
final class DatasetController
{
    private const PROJECT = ['projectId' => Requirement::UUID];
    private const DATASET = ['projectId' => Requirement::UUID, 'datasetId' => Requirement::UUID];

    public function __construct(
        private readonly DatasetRepository $datasets,
        private readonly DatasetVersionRepository $versions,
        private readonly TestCaseRepository $cases,
        private readonly DatasetImporter $importer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/projects/{projectId}/datasets', name: 'dataset_list', methods: ['GET'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function list(ProjectAccess $projectAccess, #[MapQueryString] PageQuery $query = new PageQuery()): JsonResponse
    {
        $page = $this->datasets->findPageInProject($projectAccess->project->getId(), $query->page, $query->perPage);
        $latest = $this->versions->findLatestOf($page['items']);

        return new JsonResponse([
            'items' => array_map(static fn (Dataset $dataset): array => self::datasetJson($dataset) + [
                'latestVersion' => isset($latest[$dataset->getId()->toRfc4122()]) ? self::versionJson($latest[$dataset->getId()->toRfc4122()]) : null,
            ], $page['items']),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'total' => $page['total'],
        ]);
    }

    /** Multipart: "name" and "file". Creates the dataset and its version 1. */
    #[Route('/api/projects/{projectId}/datasets', name: 'dataset_create', methods: ['POST'], requirements: self::PROJECT)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function create(ProjectAccess $projectAccess, Request $request): Response
    {
        $file = $this->importer->uploadedFile($request);
        $name = $this->datasetName($request);
        if ($this->datasets->nameIsTaken($projectAccess->project->getId(), $name)) {
            throw self::nameTaken();
        }
        $cases = $this->importer->read($file);
        if ($cases instanceof ImportErrors) {
            return $cases->toResponse();
        }

        $dataset = new Dataset($projectAccess->project, $name);
        try {
            $version = $this->entityManager->wrapInTransaction(function () use ($dataset, $file, $cases): DatasetVersion {
                $this->entityManager->persist($dataset);

                return $this->importer->saveVersion($dataset, 1, $file, $cases);
            });
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken(); // created by a concurrent request
        }

        return new JsonResponse(self::datasetJson($dataset) + ['versions' => [self::versionJson($version)]], Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}/datasets/{datasetId}', name: 'dataset_show', methods: ['GET'], requirements: self::DATASET)]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function show(ProjectAccess $projectAccess, string $datasetId): JsonResponse
    {
        $dataset = $this->findDataset($projectAccess, $datasetId);

        return new JsonResponse(self::datasetJson($dataset) + ['versions' => array_map(self::versionJson(...), $this->versions->findByDataset($dataset))]);
    }

    /** Deletes the dataset with its versions and cases (database cascade). */
    #[Route('/api/projects/{projectId}/datasets/{datasetId}', name: 'dataset_delete', methods: ['DELETE'], requirements: self::DATASET)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function delete(ProjectAccess $projectAccess, string $datasetId): Response
    {
        $dataset = $this->findDataset($projectAccess, $datasetId);
        // Slice 9: when an evaluation uses one of its versions, answer 409 in_use here, listing those evaluations
        // (architecture §4, "Deleting").
        $this->entityManager->remove($dataset);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Multipart: "file". Adds the next version; earlier versions never change. */
    #[Route('/api/projects/{projectId}/datasets/{datasetId}/versions', name: 'dataset_version_create', methods: ['POST'], requirements: self::DATASET)]
    #[IsGranted(ProjectVoter::MANAGE, 'projectAccess')]
    public function createVersion(ProjectAccess $projectAccess, string $datasetId, Request $request): Response
    {
        $dataset = $this->findDataset($projectAccess, $datasetId);
        $file = $this->importer->uploadedFile($request);
        self::rejectUnknownFields($request, []);
        $cases = $this->importer->read($file);
        if ($cases instanceof ImportErrors) {
            return $cases->toResponse();
        }

        $version = $this->entityManager->wrapInTransaction(function () use ($dataset, $file, $cases): DatasetVersion {
            // Two imports at once must not take the same number.
            $this->entityManager->lock($dataset, LockMode::PESSIMISTIC_WRITE);

            return $this->importer->saveVersion($dataset, $this->versions->nextNumber($dataset), $file, $cases);
        });

        return new JsonResponse(self::versionJson($version), Response::HTTP_CREATED);
    }

    #[Route('/api/projects/{projectId}/dataset-versions/{versionId}/cases', name: 'dataset_cases', methods: ['GET'], requirements: ['projectId' => Requirement::UUID, 'versionId' => Requirement::UUID])]
    #[IsGranted(ProjectVoter::READ, 'projectAccess')]
    public function cases(ProjectAccess $projectAccess, string $versionId, #[MapQueryString] CaseListQuery $query = new CaseListQuery()): JsonResponse
    {
        $version = $this->versions->findInProject($projectAccess->project->getId(), Uuid::fromString($versionId)) ?? throw self::notFound();
        $page = $this->cases->findPageInVersion($version, '' !== $query->tag ? $query->tag : null, '' !== $query->q ? $query->q : null, $query->page, $query->perPage);

        return new JsonResponse(['items' => $page['items'], 'page' => $query->page, 'perPage' => $query->perPage, 'total' => $page['total']]);
    }

    private function findDataset(ProjectAccess $projectAccess, string $datasetId): Dataset
    {
        return $this->datasets->findInProject($projectAccess->project->getId(), Uuid::fromString($datasetId)) ?? throw self::notFound();
    }

    /** The "name" form field, validated like a JSON body: unknown fields rejected, constraints of the DTO. */
    private function datasetName(Request $request): string
    {
        self::rejectUnknownFields($request, ['name']);
        $name = $request->request->all()['name'] ?? '';
        $body = new CreateDatasetRequest(\is_string($name) ? $name : '');
        $violations = $this->validator->validate($body);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($body, $violations));
        }

        return trim($body->name);
    }

    /**
     * @param list<string> $allowed
     */
    private static function rejectUnknownFields(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->request->all()), $allowed);
        if ([] !== $unknown) {
            throw ApiProblem::validation(array_map(static fn (int|string $field): array => ['field' => (string) $field, 'code' => 'unknown_field', 'message' => 'This field is not allowed.'], array_values($unknown)));
        }
    }

    private static function nameTaken(): ApiProblem
    {
        return ApiProblem::validation([['field' => 'name', 'code' => 'name_taken', 'message' => 'The project already has a dataset with this name. Choose another name, or import a new version of that dataset.']]);
    }

    /** The same answer as for a project you are not a member of. */
    private static function notFound(): ApiProblem
    {
        return new ApiProblem(404, 'not_found', 'Not Found');
    }

    /**
     * @return array{id: string, name: string, createdAt: string}
     */
    private static function datasetJson(Dataset $dataset): array
    {
        return [
            'id' => $dataset->getId()->toRfc4122(),
            'name' => $dataset->getName(),
            'createdAt' => $dataset->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array{id: string, number: int, caseCount: int, sourceFilename: string, sha256: string, createdAt: string}
     */
    private static function versionJson(DatasetVersion $version): array
    {
        return [
            'id' => $version->getId()->toRfc4122(),
            'number' => $version->getNumber(),
            'caseCount' => $version->getCaseCount(),
            'sourceFilename' => $version->getSourceFilename(),
            'sha256' => $version->getSha256(),
            'createdAt' => $version->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }
}
