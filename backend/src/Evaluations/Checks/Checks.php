<?php

declare(strict_types=1);

namespace App\Evaluations\Checks;

use App\Evaluations\Dto\CriterionRules;
use App\Evaluations\Entity\CheckType;

/**
 * The automatic checks (architecture §7): pure functions of an answer, its case's reference and the parameters that
 * CriterionRules stored. Each gives true (passes) or false (fails), or the code of the error that kept it from running:
 * a check error is not a failure, and the results count it apart.
 */
final class Checks
{
    public const MISSING_REFERENCE = 'missing_reference';
    public const REGEX_ERROR = 'regex_error';

    /**
     * @param array<string, mixed> $params as CriterionRules stores them
     *
     * @throws \UnexpectedValueException when a parameter is missing, which a saved rubric never is
     */
    public static function run(CheckType $type, array $params, string $output, ?string $reference): bool|string
    {
        $ignoreCase = true === ($params['ignoreCase'] ?? false);

        return match ($type) {
            CheckType::ExactMatch => self::exactMatch($output, $reference, $ignoreCase),
            CheckType::Contains => self::contains($output, self::text($params, 'text'), $ignoreCase),
            CheckType::NotContains => !self::contains($output, self::text($params, 'text'), $ignoreCase),
            CheckType::Regex => self::regex($output, self::text($params, 'pattern'), $ignoreCase),
            CheckType::Length => self::length($output, self::limit($params, 'min'), self::limit($params, 'max')),
            CheckType::ValidJson => json_validate($output),
        };
    }

    /** Equal once the white space at both ends is trimmed (mb_trim: Unicode spaces too). No reference: an error. */
    public static function exactMatch(string $output, ?string $reference, bool $ignoreCase): bool|string
    {
        if (null === $reference) {
            return self::MISSING_REFERENCE;
        }
        [$output, $reference] = [mb_trim($output), mb_trim($reference)];

        return $ignoreCase ? mb_strtolower($output) === mb_strtolower($reference) : $output === $reference;
    }

    public static function contains(string $output, string $text, bool $ignoreCase): bool
    {
        return false !== ($ignoreCase ? mb_stripos($output, $text) : mb_strpos($output, $text));
    }

    /**
     * A match anywhere in the answer, with the pattern as CriterionRules::regex() compiles it. PCRE's backtrack limit
     * (pcre.backtrack_limit) stops catastrophic backtracking, such as (a+)+$, in milliseconds: that, or any other PCRE
     * error when it runs (recursion, JIT stack, an answer that is not UTF-8), is a regex_error, never a crash. So PCRE's
     * verbs, such as (*SKIP), stay allowed: the limit is the guard, not a list of forbidden syntax.
     */
    public static function regex(string $output, string $pattern, bool $ignoreCase): bool|string
    {
        // A pattern that compiled when the rubric was saved could still warn under another PCRE version.
        set_error_handler(static fn (): bool => true);
        try {
            $matched = preg_match(CriterionRules::regex($pattern, $ignoreCase), $output);
        } finally {
            restore_error_handler();
        }

        return false === $matched ? self::REGEX_ERROR : 1 === $matched;
    }

    /** In characters (mb_strlen), between the limits inclusive; a limit not given does not apply. */
    public static function length(string $output, ?int $min, ?int $max): bool
    {
        $length = mb_strlen($output);

        return (null === $min || $length >= $min) && (null === $max || $length <= $max);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function text(array $params, string $name): string
    {
        $value = $params[$name] ?? null;

        return \is_string($value) ? $value : throw new \UnexpectedValueException("The check has no $name parameter.");
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function limit(array $params, string $name): ?int
    {
        $value = $params[$name] ?? null;

        return null === $value || \is_int($value) ? $value : throw new \UnexpectedValueException("The check's $name is not a whole number.");
    }
}
