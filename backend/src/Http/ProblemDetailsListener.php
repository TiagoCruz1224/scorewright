<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every error as application/problem+json, whatever the path: this backend only serves the API, so `/api`,
 * a path outside it or a typo never shows Symfony's debug page or a stack trace. Runs after the security listener
 * (which turns access errors into 401/403) and after the error logger, and never exposes the message of an
 * unexpected exception.
 */
#[AsEventListener(priority: -64)]
final class ProblemDetailsListener
{
    private const CODES = [
        400 => 'malformed_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'validation_failed',
        429 => 'too_many_requests',
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        $event->setResponse(self::toProblem($event->getThrowable())->toResponse());
    }

    private static function toProblem(\Throwable $exception): ApiProblem
    {
        if ($exception instanceof ApiProblem) {
            return $exception;
        }

        // allow_extra_attributes: false makes the serializer throw this for unknown request fields.
        if ($exception instanceof ExtraAttributesException) {
            return ApiProblem::validation(array_map(
                static fn (string $field): array => ['field' => $field, 'code' => 'unknown_field', 'message' => 'This field is not allowed.'],
                array_values($exception->getExtraAttributes()),
            ));
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getPrevious() instanceof ValidationFailedException) {
            return ApiProblem::validation(array_map(
                self::violationToError(...),
                iterator_to_array($exception->getPrevious()->getViolations(), false),
            ));
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return new ApiProblem($status, self::CODES[$status] ?? 'http_error', Response::$statusTexts[$status] ?? 'Error', [], $exception->getHeaders());
        }

        return new ApiProblem(500, 'internal_error', 'Internal Server Error');
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    private static function violationToError(ConstraintViolationInterface $violation): array
    {
        $constraint = $violation instanceof ConstraintViolation ? $violation->getConstraint() : null;
        $errorCode = $violation->getCode();
        // "TOO_SHORT_ERROR" → "too_short"; a Callback sets its own code (such as invalid_regex); type errors from the
        // serializer have no constraint.
        $code = match (true) {
            $constraint instanceof Callback && null !== $errorCode => $errorCode,
            null !== $constraint && null !== $errorCode => strtolower(preg_replace('/_ERROR$/', '', $constraint::getErrorName($errorCode)) ?? ''),
            default => 'invalid_type',
        };

        return ['field' => $violation->getPropertyPath(), 'code' => $code, 'message' => (string) $violation->getMessage()];
    }
}
