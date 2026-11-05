<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * An API error as RFC 9457 Problem Details with a stable machine-readable code (architecture §5).
 * Throw it from a controller or listener; ProblemDetailsListener turns it into the response.
 */
final class ApiProblem extends \RuntimeException implements HttpExceptionInterface
{
    /**
     * @param array<string, mixed>  $extra   additional members, such as "errors"
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        public readonly string $problemCode,
        string $title,
        private readonly array $extra = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($title);
    }

    /**
     * @param list<array{field: string, code: string, message: string}> $errors
     */
    public static function validation(array $errors): self
    {
        return new self(422, 'validation_failed', 'The request is not valid', ['errors' => $errors]);
    }

    /** 429 with Retry-After: the number of seconds until the rate limiter accepts the next attempt. */
    public static function tooManyRequests(string $problemCode, string $title, \DateTimeInterface $retryAt): self
    {
        return new self(429, $problemCode, $title, [], ['Retry-After' => (string) max(1, $retryAt->getTimestamp() - time())]);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'type' => '/problems/'.str_replace('_', '-', $this->problemCode),
            'title' => $this->getMessage(),
            'status' => $this->status,
            'code' => $this->problemCode,
        ] + $this->extra, $this->status, ['Content-Type' => 'application/problem+json'] + $this->headers);
    }
}
