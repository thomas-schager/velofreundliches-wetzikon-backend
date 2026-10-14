<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimit;

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

    /**
     * Shared 429 shape for every rate-limited endpoint (AuthController, PublicReportsController)
     * -- generic message on purpose, same reasoning as elsewhere in this app's auth flows: never
     * hint at *why* (wrong IP vs. wrong email bucket) or *how close* the caller was, since that's
     * information an attacker could use to tune around the limit.
     */
    protected function rateLimitedResponse(RateLimit $limit): JsonResponse
    {
        $response = $this->errorResponse('rate_limited', 'Too many requests. Please try again later.', 429);
        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $response->headers->set('Retry-After', (string) $retryAfterSeconds);

        return $response;
    }
}
