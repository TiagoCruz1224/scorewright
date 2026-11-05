<?php

declare(strict_types=1);

namespace App\Datasets\Import;

use App\Http\NoControlCharactersValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads a run file (ADR 0004, architecture §4): one answer per row, `id` (a case key of the dataset version) and
 * `output` (the answer; empty is a valid answer: the model said nothing). CSV with both columns, or a JSON array of
 * {id, output}. ImportFile reads the structure; this class checks the values. A run need not answer every case
 * (§12, decision 2), but each id must be a case of the version, once.
 */
final class RunFileReader
{
    public const MAX_OUTPUT_LENGTH = 50_000;

    private const FIELDS = ['id', 'output'];

    public function __construct(
        #[Autowire(param: 'dataset_import.max_cases')]
        private readonly int $maxAnswers,
    ) {
    }

    /**
     * @param 'csv'|'json'          $format
     * @param array<string, string> $caseIds the version's case keys => test case ids
     *
     * @return list<ImportedAnswer>|ImportErrors the answers, or the errors of the whole file
     */
    public function read(string $content, string $format, array $caseIds): array|ImportErrors
    {
        $errors = new ImportErrors('csv' === $format ? 'line' : 'item');
        $rows = new ImportFile(self::FIELDS, self::FIELDS, 'answer', $this->maxAnswers)->rows($content, $format, $errors);

        $answers = [];
        $seenKeys = [];
        foreach ($rows as $location => $row) {
            $answer = $this->answer($errors, $location, $row, $caseIds, $seenKeys);
            if (null !== $answer) {
                $answers[] = $answer;
            }
        }

        return $errors->isEmpty() ? $answers : $errors;
    }

    /**
     * @param array<string, mixed>  $row      CSV strings, or JSON values as decoded
     * @param array<string, string> $caseIds
     * @param array<string, int>    $seenKeys
     */
    private function answer(ImportErrors $errors, int $location, array $row, array $caseIds, array &$seenKeys): ?ImportedAnswer
    {
        $before = $errors->count();
        $id = $row['id'] ?? null;
        if (\is_int($id)) {
            $id = (string) $id;
        }
        $output = $row['output'] ?? null;

        if (null === $id || (\is_string($id) && '' === trim($id))) {
            $errors->add($location, 'id', 'required', 'Every answer needs the id of the case it answers.');
        } elseif (!\is_string($id)) {
            $errors->add($location, 'id', 'invalid_type', 'The id must be a string or a whole number.', (string) json_encode($id));
        }
        if (null === $output) {
            $errors->add($location, 'output', 'required', 'Every answer needs an "output". Leave it empty ("") when the model gave no answer.');
        } elseif (!\is_string($output)) {
            $errors->add($location, 'output', 'invalid_type', 'The output must be a string.', (string) json_encode($output));
        }
        if ($errors->count() !== $before || !\is_string($id) || !\is_string($output)) {
            return null;
        }

        $key = trim($id);
        if (!NoControlCharactersValidator::accepts($key)) {
            $errors->add($location, 'id', 'control_character', 'The id contains a control character (such as a line break, NUL or ESC). Remove it.', $key);
        } elseif (isset($seenKeys[$key])) {
            $errors->add($location, 'id', 'duplicate_case_key', \sprintf('Another answer (%s %d) is for the same case. Keep one answer per case.', $errors->locationKey, $seenKeys[$key]), $key);
        } elseif (!isset($caseIds[$key])) {
            $errors->add($location, 'id', 'unknown_case_key', 'No case of this dataset version has this id. Check the id, or choose the version the run answers.', $key);
        }
        $seenKeys[$key] ??= $location;

        if (!NoControlCharactersValidator::accepts($output, multiline: true)) {
            $errors->add($location, 'output', 'control_character', 'The output contains a control character (such as NUL or ESC). Remove it; tabs and line breaks are fine.', $output);
        } elseif (mb_strlen($output) > self::MAX_OUTPUT_LENGTH) {
            $errors->add($location, 'output', 'too_long', \sprintf('The output has %s characters; the limit is %s. Shorten it.', number_format(mb_strlen($output)), number_format(self::MAX_OUTPUT_LENGTH)), $output);
        }

        return $errors->count() === $before ? new ImportedAnswer($caseIds[$key], $output) : null;
    }
}
