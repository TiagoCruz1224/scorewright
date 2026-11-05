<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The text fields of a multipart request (an upload), checked like a JSON body: unknown fields answer 422
 * unknown_field, and the DTO's constraints answer 422 validation_failed with the usual errors.
 */
final class FormFields
{
    /**
     * @param list<string> $allowed
     */
    public static function rejectUnknown(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->request->all()), $allowed);
        if ([] !== $unknown) {
            throw ApiProblem::validation(array_map(static fn (int|string $field): array => ['field' => (string) $field, 'code' => 'unknown_field', 'message' => 'This field is not allowed.'], array_values($unknown)));
        }
    }

    /** A field's value, or '' when it is missing or not a string (such as name[]=…). */
    public static function string(Request $request, string $name): string
    {
        $value = $request->request->all()[$name] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * @template T of object
     *
     * @param T $dto
     *
     * @return T
     */
    public static function validated(ValidatorInterface $validator, object $dto): object
    {
        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($dto, $violations));
        }

        return $dto;
    }
}
