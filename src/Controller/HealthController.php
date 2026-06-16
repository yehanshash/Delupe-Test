<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class HealthController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * GET /health — liveness + database connectivity. Not behind the API key.
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $databaseConnected = true;

        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (Throwable) {
            $databaseConnected = false;
        }

        return new JsonResponse(
            [
                'status' => $databaseConnected ? 'ok' : 'error',
                'database' => $databaseConnected ? 'connected' : 'disconnected',
            ],
            $databaseConnected ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
