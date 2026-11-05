<?php

declare(strict_types=1);

namespace App\Datasets\Import;

/**
 * The part of reading an import file that every format shares (ADR 0004): UTF-8 with an optional byte order mark, CSV
 * with a header row (comma or semicolon, RFC 4180 quotes) or a JSON array of objects, the allowed columns or fields,
 * and the row limit. It turns the file into rows and records what is wrong with its structure; CaseFileReader and
 * RunFileReader check the values.
 */
final readonly class ImportFile
{
    /** Objects and arrays nested inside one JSON row (a case's metadata, for example). */
    public const MAX_NESTING = 30;

    /**
     * @param list<string> $fields   the columns of a CSV header and the fields of a JSON row
     * @param list<string> $required the columns a CSV header must have (JSON rows are checked by the reader)
     * @param string       $row      what one row is ("case", "answer"), for the messages
     */
    public function __construct(
        private array $fields,
        private array $required,
        private string $row,
        private int $maxRows,
    ) {
    }

    /**
     * The rows that are well formed, keyed by line (CSV, the header being line 1) or item (JSON, from 1). A CSV value
     * is a string; a JSON value is as decoded (objects as stdClass). A row with a structural error is left out, and
     * the error recorded.
     *
     * @param 'csv'|'json' $format
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $content, string $format, ImportErrors $errors): array
    {
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }
        if ('' === trim($content)) {
            $errors->add(null, null, 'empty_file', 'The file is empty. Add the '.$this->row.'s, as described in the import format.');

            return [];
        }

        return 'csv' === $format ? $this->csvRows($content, $errors) : $this->jsonRows($content, $errors);
    }

    /**
     * json_decode() counts the values inside the innermost object or array as one more level.
     *
     * @param int<1, max> $levels
     *
     * @return int<2, max>
     */
    public static function jsonDepth(int $levels): int
    {
        return $levels + 1;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function csvRows(string $content, ImportErrors $errors): array
    {
        $delimiter = self::delimiter($content);
        $columns = null;
        $rows = [];
        $count = 0;
        foreach (self::csvRecords($content, $delimiter) as $line => $values) {
            if (null === $values) {
                $errors->add($line, null, 'invalid_csv', 'A quote is not closed, or a quoted value has more text after its closing quote. Put quotes around the whole value and double every quote inside it, like "say ""hi"" twice".');
                break;
            }
            if (false === $values) {
                $errors->add($line, null, 'too_long', 'A value on this line is far too long to read. Shorten it.');
                break;
            }
            if ([''] === $values) {
                continue; // a blank line
            }
            if (null === $columns) {
                $columns = $this->header($errors, $line, $values);
                if (!$errors->isEmpty()) {
                    return [];
                }
                continue;
            }

            if (++$count === $this->maxRows + 1) {
                $errors->add($line, null, 'too_many_rows', $this->tooManyRows());
            }
            if (\count($values) !== \count($columns)) {
                $errors->add($line, null, 'invalid_csv', \sprintf('This line has %d values, but the header has %d columns. Put quotes around any value that contains "%s" or a line break.', \count($values), \count($columns), $delimiter), implode($delimiter, $values));
                continue;
            }
            $row = array_combine($columns, $values);
            $valid = true;
            foreach ($row as $column => $value) {
                if (!mb_check_encoding($value, 'UTF-8')) {
                    $errors->add($line, $column, 'invalid_utf8', 'This value is not valid UTF-8. Save the file with the UTF-8 encoding (in Excel: Save As, "CSV UTF-8").', $value);
                    $valid = false;
                }
            }
            if ($valid) {
                $rows[$line] = $row;
            }
        }

        if (0 === $count && $errors->isEmpty()) {
            $errors->add(null, null, 'empty_file', \sprintf('The file has a header but no %ss. Add one line per %s below the header.', $this->row, $this->row));
        }

        return $rows;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function header(ImportErrors $errors, int $line, array $values): array
    {
        $columns = [];
        foreach ($values as $value) {
            $column = strtolower(trim($value));
            if (!\in_array($column, $this->fields, true)) {
                $errors->add($line, $value, 'unknown_column', \sprintf('This column is not part of the import format. Use only %s, and remove the others.', $this->fieldList()), $value);
            } elseif (\in_array($column, $columns, true)) {
                $errors->add($line, $column, 'duplicate_column', 'This column appears twice in the header. Keep one of them.');
            }
            $columns[] = $column;
        }
        foreach (array_diff($this->required, $columns) as $missing) {
            $errors->add($line, $missing, 'missing_column', \sprintf('The header has no "%s" column. The first line must name the columns, for example: %s.', $missing, implode(',', $this->fields)));
        }

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jsonRows(string $content, ImportErrors $errors): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $errors->add(null, null, 'invalid_utf8', 'The file is not valid UTF-8. Save it with the UTF-8 encoding and try again.');

            return [];
        }
        try {
            // The array and a row object come before the nested values.
            $items = json_decode($content, false, self::jsonDepth(2 + self::MAX_NESTING), \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $errors->add(null, null, 'invalid_json', \JSON_ERROR_DEPTH === $exception->getCode()
                ? \sprintf('Values inside a %s are nested deeper than %d levels. Flatten them.', $this->row, self::MAX_NESTING)
                : 'The file is not valid JSON ('.$exception->getMessage().'). Check it with a JSON validator.');

            return [];
        }
        if (!\is_array($items)) {
            $errors->add(null, null, 'invalid_json', \sprintf('The file must be a JSON array of %ss, like [{…}, {…}].', $this->row));

            return [];
        }
        if ([] === $items) {
            $errors->add(null, null, 'empty_file', \sprintf('The array has no %ss. Add at least one.', $this->row));

            return [];
        }

        $rows = [];
        foreach (array_values($items) as $index => $item) {
            $number = $index + 1;
            if ($number === $this->maxRows + 1) {
                $errors->add($number, null, 'too_many_rows', $this->tooManyRows());
            }
            if (!$item instanceof \stdClass) {
                $errors->add($number, null, 'invalid_type', \sprintf('Each %s must be a JSON object, like {…}.', $this->row), (string) json_encode($item));
                continue;
            }
            $fields = get_object_vars($item);
            $unknown = array_diff(array_keys($fields), $this->fields);
            foreach ($unknown as $field) {
                $errors->add($number, (string) $field, 'unknown_field', \sprintf('This field is not part of the import format. Use only %s.', $this->fieldList()));
            }
            if ([] === $unknown) {
                $rows[$number] = $fields;
            }
        }

        return $rows;
    }

    private function tooManyRows(): string
    {
        return \sprintf('The file has more than %s %ss, the limit. Split it into several files.', number_format($this->maxRows), $this->row);
    }

    private function fieldList(): string
    {
        return implode(', ', \array_slice($this->fields, 0, -1)).' and '.implode('', \array_slice($this->fields, -1));
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
