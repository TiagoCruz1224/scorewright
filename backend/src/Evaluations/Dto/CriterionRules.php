<?php

declare(strict_types=1);

namespace App\Evaluations\Dto;

use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use App\Http\NoControlCharactersValidator;

/**
 * One criterion of a rubric body (architecture §4, §7): {kind, name, guidance, weight, checkType, checkParams}.
 * - scale_1_5 and pass_fail need guidance and take no check;
 * - automatic needs a checkType, whose parameters are checked here when the rubric is saved; the checks themselves run
 *   in slice 10.
 *
 * Errors name the field from the criterion ("checkParams.pattern"); SaveRubricRequest puts "criteria[N]." before it.
 */
final class CriterionRules
{
    public const MAX_CRITERIA = 30;
    public const NAME_LENGTH = 200;
    public const GUIDANCE_LENGTH = 5000;
    public const MAX_WEIGHT = 100;
    /** For the text of contains and not_contains, and a regex pattern. */
    public const PARAMETER_LENGTH = 500;
    /** The largest limit of a length check: the length of the longest answer. */
    public const MAX_LENGTH_LIMIT = 50_000;

    private const FIELDS = ['kind', 'name', 'guidance', 'weight', 'checkType', 'checkParams'];

    private const PARAMETERS = [
        'exact_match' => ['ignoreCase'],
        'contains' => ['text', 'ignoreCase'],
        'not_contains' => ['text', 'ignoreCase'],
        'regex' => ['pattern', 'ignoreCase'],
        'length' => ['min', 'max'],
        'valid_json' => [],
    ];

    /**
     * @return CriterionData|list<array{string, string, string}> the criterion, or its errors as [field, code, message]
     */
    public static function parse(mixed $criterion): CriterionData|array
    {
        if (!self::isObject($criterion)) {
            return [['', 'invalid_type', 'Each criterion is an object with a kind and a name.']];
        }

        $errors = [];
        foreach (array_diff(array_keys($criterion), self::FIELDS) as $field) {
            $errors[] = [(string) $field, 'unknown_field', 'This field is not allowed.'];
        }
        $kind = self::choice($criterion['kind'] ?? null, CriterionKind::class, 'kind', 'Choose the kind: scale_1_5, pass_fail or automatic.', $errors);
        $name = self::text($criterion['name'] ?? null, 'name', self::NAME_LENGTH, 'Give the criterion a name.', $errors);
        $manual = null !== $kind && CriterionKind::Automatic !== $kind;
        $guidance = self::text($criterion['guidance'] ?? null, 'guidance', self::GUIDANCE_LENGTH, $manual ? 'Write the guidance: how evaluators score this criterion.' : null, $errors, multiline: true);
        $weight = self::weight($criterion['weight'] ?? null, $errors);

        $checkType = null;
        $checkParams = null;
        if ($manual) {
            foreach (['checkType', 'checkParams'] as $field) {
                if (null !== ($criterion[$field] ?? null)) {
                    $errors[] = [$field, 'not_allowed', 'Only automatic criteria have a check. Remove it, or make the criterion automatic.'];
                }
            }
        } elseif (CriterionKind::Automatic === $kind) {
            $checkType = self::choice($criterion['checkType'] ?? null, CheckType::class, 'checkType', 'Choose the check: exact_match, contains, not_contains, regex, length or valid_json.', $errors);
            $parameters = $criterion['checkParams'] ?? [];
            if (!self::isObject($parameters)) {
                $errors[] = ['checkParams', 'invalid_type', 'The check parameters are an object, such as {"text": "refund"}.'];
            } elseif (null !== $checkType) {
                $checkParams = self::checkParams($checkType, $parameters, $errors);
            }
        }

        if ([] !== $errors || null === $kind || null === $name || null === $guidance) {
            return $errors;
        }

        return new CriterionData($kind, trim($name), $guidance, $weight, $checkType, $checkParams);
    }

    /**
     * A regex check's pattern as PHP gives it to PCRE: between \x01 delimiters, which no pattern can contain (control
     * characters are refused), so a "/" needs no escaping; always UTF-8 (u), and case-insensitive (i) with ignoreCase.
     */
    public static function regex(string $pattern, bool $ignoreCase): string
    {
        return "\x01".$pattern."\x01u".($ignoreCase ? 'i' : '');
    }

    /**
     * @phpstan-assert-if-true array<mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        // JSON {} decodes to [] like JSON []; a non-empty list is an array, not an object.
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T>                     $enum
     * @param list<array{string, string, string}> $errors
     *
     * @return T|null
     */
    private static function choice(mixed $value, string $enum, string $field, string $message, array &$errors): ?\BackedEnum
    {
        $choice = \is_string($value) ? $enum::tryFrom($value) : null;
        if (null === $choice) {
            $errors[] = [$field, match (true) {
                null === $value, '' === $value => 'is_blank',
                \is_string($value) => 'no_such_choice',
                default => 'invalid_type',
            }, $message];
        }

        return $choice;
    }

    /**
     * Text of at most $max characters, without control characters (tab and line breaks are fine when $multiline).
     * Missing is '' unless $required gives the message for a blank value.
     *
     * @param list<array{string, string, string}> $errors
     */
    private static function text(mixed $value, string $field, int $max, ?string $required, array &$errors, bool $multiline = false): ?string
    {
        $value ??= '';
        if (!\is_string($value)) {
            $errors[] = [$field, 'invalid_type', 'This value should be text.'];
        } elseif (null !== $required && '' === trim($value)) {
            $errors[] = [$field, 'is_blank', $required];
        } elseif (mb_strlen($value) > $max) {
            $errors[] = [$field, 'too_long', 'Use at most '.number_format($max).' characters.'];
        } elseif (!NoControlCharactersValidator::accepts($value, $multiline)) {
            $errors[] = [$field, 'control_character', 'This value contains a control character, which is not allowed.'];
        } else {
            return $value;
        }

        return null;
    }

    /**
     * @param list<array{string, string, string}> $errors
     */
    private static function weight(mixed $value, array &$errors): ?float
    {
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) && !\is_float($value)) {
            $errors[] = ['weight', 'invalid_type', 'The weight is a number, such as 1 or 2.5.'];
        } elseif ($value <= 0 || $value > self::MAX_WEIGHT) {
            $errors[] = ['weight', 'not_in_range', 'Use a weight above 0 and at most 100.'];
        } else {
            return (float) $value;
        }

        return null;
    }

    /**
     * The parameters as stored, with their defaults (ignoreCase: false; a length limit not given: null).
     *
     * @param array<mixed>                        $parameters
     * @param list<array{string, string, string}> $errors
     *
     * @return array<string, mixed>
     */
    private static function checkParams(CheckType $type, array $parameters, array &$errors): array
    {
        foreach (array_diff(array_keys($parameters), self::PARAMETERS[$type->value]) as $field) {
            $errors[] = ['checkParams.'.$field, 'unknown_field', 'This check has no such parameter.'];
        }
        $ignoreCase = $parameters['ignoreCase'] ?? false;
        if (!\is_bool($ignoreCase)) {
            $errors[] = ['checkParams.ignoreCase', 'invalid_type', 'Use true or false.'];
        }

        return match ($type) {
            CheckType::ExactMatch => ['ignoreCase' => $ignoreCase],
            CheckType::Contains, CheckType::NotContains => [
                'text' => self::text($parameters['text'] ?? null, 'checkParams.text', self::PARAMETER_LENGTH, 'Write the text to look for.', $errors),
                'ignoreCase' => $ignoreCase,
            ],
            CheckType::Regex => ['pattern' => self::pattern($parameters['pattern'] ?? null, $errors), 'ignoreCase' => $ignoreCase],
            CheckType::Length => self::lengthLimits($parameters, $errors),
            CheckType::ValidJson => [],
        };
    }

    /**
     * @param list<array{string, string, string}> $errors
     */
    private static function pattern(mixed $value, array &$errors): ?string
    {
        $pattern = self::text($value, 'checkParams.pattern', self::PARAMETER_LENGTH, 'Write the pattern, such as ^Refund.', $errors);
        if (null === $pattern) {
            return null;
        }
        $reason = self::compileError(self::regex($pattern, false));
        if (null !== $reason) {
            $errors[] = ['checkParams.pattern', 'invalid_regex', "This pattern does not compile: $reason. Fix it, or put a backslash before a special character to match it as text."];

            return null;
        }

        return $pattern;
    }

    /** PCRE's reason when the regex does not compile, such as "missing closing parenthesis at offset 7". */
    private static function compileError(string $regex): ?string
    {
        $warning = '';
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $compiles = false !== preg_match($regex, '');
        } finally {
            restore_error_handler();
        }
        if ($compiles) {
            return null;
        }
        // A pattern that ends in a lone backslash escapes the closing delimiter; PCRE itself would say this.
        if (str_contains($warning, 'No ending delimiter')) {
            return '\ at end of pattern';
        }

        // "preg_match(): Compilation failed: missing closing parenthesis at offset 7"
        return preg_replace('/^preg_match\(\): (Compilation failed: )?/', '', $warning) ?: preg_last_error_msg();
    }

    /**
     * @param array<mixed>                        $parameters
     * @param list<array{string, string, string}> $errors
     *
     * @return array{min: int|null, max: int|null}
     */
    private static function lengthLimits(array $parameters, array &$errors): array
    {
        $limits = ['min' => null, 'max' => null];
        foreach (array_keys($limits) as $field) {
            $value = $parameters[$field] ?? null;
            if (null === $value) {
                continue;
            }
            if (!\is_int($value) || $value < 0 || $value > self::MAX_LENGTH_LIMIT) {
                $errors[] = ['checkParams.'.$field, \is_int($value) ? 'not_in_range' : 'invalid_type', 'Use a whole number of characters from 0 to 50,000.'];
                continue;
            }
            $limits[$field] = $value;
        }

        if (null === ($parameters['min'] ?? null) && null === ($parameters['max'] ?? null)) {
            $errors[] = ['checkParams.min', 'is_blank', 'Give a minimum, a maximum or both.'];
        } elseif (null !== $limits['min'] && null !== $limits['max'] && $limits['min'] > $limits['max']) {
            $errors[] = ['checkParams.max', 'max_below_min', 'The maximum must be at least the minimum.'];
        }

        return $limits;
    }
}
