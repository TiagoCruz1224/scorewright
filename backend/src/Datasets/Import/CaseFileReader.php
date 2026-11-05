<?php

declare(strict_types=1);

namespace App\Datasets\Import;

use App\Http\NoControlCharactersValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads a whole dataset file in memory and checks every case (ADR 0004, architecture §4). ImportFile reads the
 * structure; this class checks the values. The format is in docs/api.md:
 *
 * - CSV: a header row naming the columns: `input`, and optionally `id`, `reference`, `tags` (comma-separated in one
 *   cell) and `metadata` (a JSON object in one cell). Comma or semicolon delimited, with RFC 4180 quotes.
 * - JSON: an array of objects with the same fields; `tags` is an array of strings and `metadata` an object.
 *
 * Both are UTF-8; a byte order mark is ignored. A case's key is its `id`, or else its line number minus one (CSV) or
 * its item number (JSON).
 */
final class CaseFileReader
{
    public const MAX_KEY_LENGTH = 100;
    public const MAX_TEXT_LENGTH = 20_000;
    public const MAX_TAGS = 20;
    public const MAX_TAG_LENGTH = 50;
    public const MAX_METADATA_BYTES = 10_240;
    /** Nested objects and arrays in metadata, the outer object included. */
    public const MAX_METADATA_DEPTH = 30;

    private const FIELDS = ['id', 'input', 'reference', 'tags', 'metadata'];

    public function __construct(
        #[Autowire(param: 'dataset_import.max_cases')]
        private readonly int $maxCases,
    ) {
    }

    /**
     * @param 'csv'|'json' $format
     *
     * @return list<ImportedCase>|ImportErrors the cases, or the errors of the whole file
     */
    public function read(string $content, string $format): array|ImportErrors
    {
        $errors = new ImportErrors('csv' === $format ? 'line' : 'item');
        $rows = new ImportFile(self::FIELDS, ['input'], 'case', $this->maxCases)->rows($content, $format, $errors);

        // Ids are all or nothing: an id column (CSV), or one case with an id (JSON), means every case needs one.
        $usesIds = [] !== array_filter($rows, static fn (array $row): bool => \array_key_exists('id', $row));
        $cases = [];
        $seenKeys = [];
        foreach ($rows as $location => $row) {
            $case = 'csv' === $format ? $this->csvCase($errors, $location, $row, $seenKeys) : $this->jsonCase($errors, $location, $row, $usesIds, $seenKeys);
            if (null !== $case) {
                $cases[] = $case;
            }
        }

        return $errors->isEmpty() ? $cases : $errors;
    }

    /**
     * @param array<string, mixed> $row      column => value (a string: this is CSV)
     * @param array<string, int>   $seenKeys
     */
    private function csvCase(ImportErrors $errors, int $line, array $row, array &$seenKeys): ?ImportedCase
    {
        $cell = static fn (string $column): ?string => isset($row[$column]) ? (string) $row[$column] : null;
        $tags = array_values(array_filter(array_map(trim(...), explode(',', $cell('tags') ?? '')), static fn (string $tag): bool => '' !== $tag));
        $metadata = '{}';
        if ('' !== trim($cell('metadata') ?? '')) {
            try {
                $decoded = json_decode((string) $cell('metadata'), false, ImportFile::jsonDepth(self::MAX_METADATA_DEPTH), \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            $metadata = self::encodeMetadata($errors, $line, $decoded, (string) $cell('metadata'));
        }

        return $this->toCase($errors, $line, (string) ($line - 1), $cell('id'), (string) $cell('input'), $cell('reference'), $tags, $metadata, $seenKeys);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int>   $seenKeys
     */
    private function jsonCase(ImportErrors $errors, int $item, array $fields, bool $usesIds, array &$seenKeys): ?ImportedCase
    {
        $before = $errors->count();
        $id = $fields['id'] ?? ($usesIds ? '' : null);
        if (\is_int($id)) {
            $id = (string) $id;
        }
        $input = $fields['input'] ?? '';
        $reference = $fields['reference'] ?? null;
        $tags = $fields['tags'] ?? [];
        foreach (['id' => $id, 'input' => $input, 'reference' => $reference] as $field => $value) {
            if (null !== $value && !\is_string($value)) {
                $errors->add($item, $field, 'invalid_type', 'id' === $field ? 'The id must be a string or a whole number.' : "The $field must be a string.", (string) json_encode($value));
            }
        }
        if (!\is_array($tags) || [] !== array_filter($tags, static fn (mixed $tag): bool => !\is_string($tag))) {
            $errors->add($item, 'tags', 'invalid_type', 'The tags must be an array of strings, like ["billing", "refund"].', (string) json_encode($tags));
        }
        $metadata = isset($fields['metadata']) ? self::encodeMetadata($errors, $item, $fields['metadata'], (string) json_encode($fields['metadata'])) : '{}';

        if ($errors->count() !== $before || !\is_string($input) || !\is_array($tags)
            || (null !== $id && !\is_string($id)) || (null !== $reference && !\is_string($reference))) {
            return null;
        }

        $tags = array_values(array_filter(array_map(static fn (mixed $tag): string => trim((string) $tag), $tags), static fn (string $tag): bool => '' !== $tag));

        return $this->toCase($errors, $item, (string) $item, $id, $input, $reference, $tags, $metadata, $seenKeys);
    }

    /**
     * Checks one case against the limits; returns it, or records its errors and returns null.
     *
     * @param string|null        $id       the case's id ('' when missing from a file that uses ids), null without ids
     * @param list<string>       $tags
     * @param string|null        $metadata a JSON object, or null when it was invalid (already recorded)
     * @param array<string, int> $seenKeys
     */
    private function toCase(ImportErrors $errors, int $location, string $generatedKey, ?string $id, string $input, ?string $reference, array $tags, ?string $metadata, array &$seenKeys): ?ImportedCase
    {
        $before = $errors->count();
        $key = $generatedKey;
        if (null !== $id) {
            $key = trim($id);
            if ('' === $key) {
                $errors->add($location, 'id', 'required', 'This case has no id, but others do. Give every case an id, or remove all ids to number the cases automatically.');
            } elseif (!NoControlCharactersValidator::accepts($key)) {
                $errors->add($location, 'id', 'control_character', 'The id contains a control character (such as a line break, NUL or ESC). Remove it.', $key);
            } elseif (mb_strlen($key) > self::MAX_KEY_LENGTH) {
                $errors->add($location, 'id', 'too_long', \sprintf('The id is longer than %d characters. Shorten it.', self::MAX_KEY_LENGTH), $key);
            } elseif (isset($seenKeys[$key])) {
                $errors->add($location, 'id', 'duplicate_case_key', \sprintf('Another case (%s %d) already uses this id. Each id must be unique in the file.', $errors->locationKey, $seenKeys[$key]), $key);
            } else {
                $seenKeys[$key] = $location;
            }
        }

        self::checkText($errors, $location, 'input', $input);
        if (null !== $reference && '' === trim($reference)) {
            $reference = null;
        }
        if (null !== $reference) {
            self::checkText($errors, $location, 'reference', $reference);
        }

        if (\count($tags) > self::MAX_TAGS) {
            $errors->add($location, 'tags', 'too_many_tags', \sprintf('This case has %d tags; the limit is %d. Remove some.', \count($tags), self::MAX_TAGS), implode(', ', $tags));
        }
        foreach ($tags as $tag) {
            if (!NoControlCharactersValidator::accepts($tag)) {
                $errors->add($location, 'tags', 'control_character', 'A tag contains a control character (such as a line break, NUL or ESC). Remove it.', $tag);
            } elseif (mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                $errors->add($location, 'tags', 'tag_too_long', \sprintf('A tag is longer than %d characters. Shorten it.', self::MAX_TAG_LENGTH), $tag);
            }
        }

        if (null === $metadata || $errors->count() !== $before) {
            return null;
        }

        return new ImportedCase($key, $input, $reference, array_values(array_unique($tags)), $metadata);
    }

    private static function checkText(ImportErrors $errors, int $location, string $field, string $value): void
    {
        if (!NoControlCharactersValidator::accepts($value, multiline: true)) {
            $errors->add($location, $field, 'control_character', "The $field contains a control character (such as NUL or ESC). Remove it; tabs and line breaks are fine.", $value);
        } elseif ('input' === $field && '' === trim($value)) {
            $errors->add($location, $field, 'required', 'The input is empty. Every case needs the prompt text in "input".');
        } elseif (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            $errors->add($location, $field, 'too_long', \sprintf('The %s has %s characters; the limit is %s. Shorten it.', $field, number_format(mb_strlen($value)), number_format(self::MAX_TEXT_LENGTH)), $value);
        }
    }

    /**
     * @param mixed $value the decoded metadata; it must be an object
     *
     * @return string|null the JSON object, or null after recording the error
     */
    private static function encodeMetadata(ImportErrors $errors, int $location, mixed $value, string $excerpt): ?string
    {
        if (!$value instanceof \stdClass) {
            $errors->add($location, 'metadata', 'invalid_metadata', 'The metadata must be a JSON object, like {"source": "support", "priority": 2}, or be left out.', $excerpt);

            return null;
        }

        try {
            $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            // JSON allows 1e999, but it does not fit in a 64-bit float: PHP reads it as INF, which JSON cannot store.
            $path = self::infinitePath($value) ?? '';
            $errors->add($location, 'metadata', 'invalid_metadata', \sprintf('The number at "%s" in the metadata is too large to store. Use a smaller number, or put it in quotes as text.', $path), '' !== $excerpt ? $excerpt : $path);

            return null;
        }
        if (\strlen($json) > self::MAX_METADATA_BYTES) {
            $errors->add($location, 'metadata', 'metadata_too_large', \sprintf('The metadata takes %s bytes as JSON; the limit is %s. Keep only what you need.', number_format(\strlen($json)), number_format(self::MAX_METADATA_BYTES)), $json);

            return null;
        }
        // PostgreSQL's jsonb cannot store a NUL character.
        if (str_contains($json, '\u0000')) {
            $errors->add($location, 'metadata', 'control_character', 'The metadata contains a NUL character (\u0000). Remove it.', $json);

            return null;
        }

        return $json;
    }

    /** Where the first number too large for a 64-bit float is, such as "limits.max" or "sizes[2]". */
    private static function infinitePath(mixed $value, string $path = ''): ?string
    {
        if (\is_float($value) && !is_finite($value)) {
            return $path;
        }
        $children = $value instanceof \stdClass ? get_object_vars($value) : (\is_array($value) ? $value : []);
        foreach ($children as $key => $child) {
            $found = self::infinitePath($child, match (true) {
                \is_int($key) && \is_array($value) => $path."[$key]",
                '' === $path => (string) $key,
                default => "$path.$key",
            });
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
