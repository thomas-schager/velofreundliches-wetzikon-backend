<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared JSON error shape for all API controllers, matching api/openapi.yaml's Error schema
 * ({ error, message }).
 */
abstract class AbstractApiController extends AbstractController
{
    /** @param string[] $details */
    protected function errorResponse(string $error, string $message, int $status, array $details = []): JsonResponse
    {
        $body = ['error' => $error, 'message' => $message];
        if ($details !== []) {
            $body['details'] = $details;
        }

        return new JsonResponse($body, $status);
    }
}
