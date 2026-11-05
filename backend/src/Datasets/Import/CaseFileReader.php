<?php

declare(strict_types=1);

namespace App\Datasets\Import;

use App\Http\NoControlCharactersValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads a whole dataset file in memory and checks every case (ADR 0004, architecture §4). The format is in
 * docs/api.md:
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
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }

        if ('' === trim($content)) {
            $errors->add(null, null, 'empty_file', 'The file is empty. Add the cases, as described in the import format.');
            $cases = [];
        } else {
            $cases = 'csv' === $format ? $this->readCsv($content, $errors) : $this->readJson($content, $errors);
        }

        return $errors->isEmpty() ? $cases : $errors;
    }

    /**
     * @return list<ImportedCase>
     */
    private function readCsv(string $content, ImportErrors $errors): array
    {
        $delimiter = self::delimiter($content);
        $columns = null;
        $cases = [];
        $seenKeys = [];
        $count = 0;
        foreach (self::csvRecords($content, $delimiter) as $line => $values) {
            if (null === $values) {
                $errors->add($line, null, 'invalid_csv', 'A quote is not closed, or a quoted value has more text after its closing quote. Put quotes around the whole value and double every quote inside it, like "say ""hi"" twice".');
                break;
            }
            if (false === $values) {
                $errors->add($line, null, 'too_long', \sprintf('A value on this line is far too long to read; the limit is %s characters. Shorten it.', number_format(self::MAX_TEXT_LENGTH)));
                break;
            }
            if ([''] === $values) {
                continue; // a blank line
            }
            if (null === $columns) {
                $columns = self::readHeader($errors, $line, $values);
                if (!$errors->isEmpty()) {
                    return [];
                }
                continue;
            }

            if (++$count === $this->maxCases + 1) {
                $errors->add($line, null, 'too_many_rows', \sprintf('The file has more than %s cases, the limit. Split it into several datasets.', number_format($this->maxCases)));
            }
            if (\count($values) !== \count($columns)) {
                $errors->add($line, null, 'invalid_csv', \sprintf('This line has %d values, but the header has %d columns. Put quotes around any value that contains "%s" or a line break.', \count($values), \count($columns), $delimiter), implode($delimiter, $values));
                continue;
            }

            $case = $this->csvCase($errors, $line, array_combine($columns, $values), $seenKeys);
            if (null !== $case) {
                $cases[] = $case;
            }
        }

        if (0 === $count && $errors->isEmpty()) {
            $errors->add(null, null, 'empty_file', 'The file has a header but no cases. Add one line per case below the header.');
        }

        return $cases;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function readHeader(ImportErrors $errors, int $line, array $values): array
    {
        $columns = [];
        foreach ($values as $value) {
            $column = strtolower(trim($value));
            if (!\in_array($column, self::FIELDS, true)) {
                $errors->add($line, $value, 'unknown_column', 'This column is not part of the import format. Use only id, input, reference, tags and metadata; remove the column, or move its values into metadata.', $value);
            } elseif (\in_array($column, $columns, true)) {
                $errors->add($line, $column, 'duplicate_column', 'This column appears twice in the header. Keep one of them.');
            }
            $columns[] = $column;
        }
        if (!\in_array('input', $columns, true)) {
            $errors->add($line, 'input', 'missing_column', 'The header has no "input" column. The first line must name the columns, for example: id,input,reference,tags,metadata.');
        }

        return $columns;
    }

    /**
     * @param array<string, string> $row      column => value
     * @param array<string, int>    $seenKeys
     */
    private function csvCase(ImportErrors $errors, int $line, array $row, array &$seenKeys): ?ImportedCase
    {
        $valid = true;
        foreach ($row as $column => $value) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $errors->add($line, $column, 'invalid_utf8', 'This value is not valid UTF-8. Save the file with the UTF-8 encoding (in Excel: Save As, "CSV UTF-8").', $value);
                $valid = false;
            }
        }
        if (!$valid) {
            return null;
        }

        $tags = array_values(array_filter(array_map(trim(...), explode(',', $row['tags'] ?? '')), static fn (string $tag): bool => '' !== $tag));
        $metadata = '{}';
        if ('' !== trim($row['metadata'] ?? '')) {
            try {
                $decoded = json_decode($row['metadata'], false, self::jsonDepth(self::MAX_METADATA_DEPTH), \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            $metadata = self::encodeMetadata($errors, $line, $decoded, $row['metadata']);
        }

        return $this->toCase($errors, $line, (string) ($line - 1), $row['id'] ?? null, $row['input'], $row['reference'] ?? null, $tags, $metadata, $seenKeys);
    }

    /**
     * @return list<ImportedCase>
     */
    private function readJson(string $content, ImportErrors $errors): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $errors->add(null, null, 'invalid_utf8', 'The file is not valid UTF-8. Save it with the UTF-8 encoding and try again.');

            return [];
        }
        try {
            // The array and a case object come before the metadata.
            $items = json_decode($content, false, self::jsonDepth(2 + self::MAX_METADATA_DEPTH), \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $errors->add(null, null, 'invalid_json', \JSON_ERROR_DEPTH === $exception->getCode()
                ? \sprintf('The metadata is nested deeper than %d levels. Flatten it.', self::MAX_METADATA_DEPTH)
                : 'The file is not valid JSON ('.$exception->getMessage().'). Check it with a JSON validator.');

            return [];
        }
        if (!\is_array($items)) {
            $errors->add(null, null, 'invalid_json', 'The file must be a JSON array of cases, like [{"input": "…"}, {"input": "…"}].');

            return [];
        }
        if ([] === $items) {
            $errors->add(null, null, 'empty_file', 'The array has no cases. Add at least one, like {"input": "…"}.');

            return [];
        }

        // Ids are all or nothing: one case with an id means every case needs one.
        $usesIds = [] !== array_filter($items, static fn (mixed $item): bool => $item instanceof \stdClass && property_exists($item, 'id'));
        $cases = [];
        $seenKeys = [];
        foreach (array_values($items) as $index => $item) {
            $number = $index + 1;
            if ($number === $this->maxCases + 1) {
                $errors->add($number, null, 'too_many_rows', \sprintf('The file has more than %s cases, the limit. Split it into several datasets.', number_format($this->maxCases)));
            }
            if (!$item instanceof \stdClass) {
                $errors->add($number, null, 'invalid_type', 'Each case must be a JSON object, like {"input": "…"}.', (string) json_encode($item));
                continue;
            }

            $case = $this->jsonCase($errors, $number, get_object_vars($item), $usesIds, $seenKeys);
            if (null !== $case) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, int>   $seenKeys
     */
    private function jsonCase(ImportErrors $errors, int $item, array $fields, bool $usesIds, array &$seenKeys): ?ImportedCase
    {
        $before = $errors->count();
        foreach (array_diff(array_keys($fields), self::FIELDS) as $unknown) {
            $errors->add($item, (string) $unknown, 'unknown_field', 'This field is not part of the import format. Use only id, input, reference, tags and metadata; move anything else into metadata.');
        }

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

        $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
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

    /**
     * json_decode() counts the values inside the innermost object or array as one more level.
     *
     * @param int<1, max> $levels
     *
     * @return int<2, max>
     */
    private static function jsonDepth(int $levels): int
    {
        return $levels + 1;
    }

    /** Semicolons when the first line has more of them than commas (spreadsheets in many European locales). */
    private static function delimiter(string $csv): string
    {
        $firstLine = substr($csv, 0, strcspn($csv, "\r\n"));

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * RFC 4180 records keyed by line, as a spreadsheet numbers them: the header is line 1, and a quoted value over
     * several physical lines is still one line. Yields null at a quoting error, or false when PCRE gives up (a quoted
     * value of megabytes with many escaped quotes exhausts pcre.backtrack_limit), and stops there.
     *
     * @return \Generator<int, list<string>|false|null>
     */
    private static function csvRecords(string $csv, string $delimiter): \Generator
    {
        // One field and what ends it: a quoted value ("" is a quote), or an unquoted one without quotes or line breaks.
        $field = '/\G(?:"((?:[^"]++|"")*+)"|([^"'.$delimiter.'\r\n]*+))('.$delimiter.'|\r\n?|\n|\z)/';
        $length = \strlen($csv);
        $offset = 0;
        $line = 1;
        $record = [];
        while (true) {
            $matched = preg_match($field, $csv, $match, \PREG_UNMATCHED_AS_NULL, $offset);
            if (1 !== $matched) {
                yield $line => false === $matched ? false : null;

                return;
            }
            $record[] = null !== $match[1] ? str_replace('""', '"', $match[1]) : (string) $match[2];
            $offset += \strlen((string) $match[0]);
            if ($delimiter !== $match[3]) {
                yield $line++ => $record;
                $record = [];
                if ($offset >= $length) {
                    return;
                }
            }
        }
    }
}
