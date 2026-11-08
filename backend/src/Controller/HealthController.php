<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiProblem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (DBALException) {
            return ApiProblem::databaseUnavailable()->toResponse();
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
