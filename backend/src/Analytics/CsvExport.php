<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Evaluations\Entity\Criterion;
use App\Evaluations\Entity\CriterionKind;
use App\Evaluations\Entity\Evaluation;
use Doctrine\DBAL\Connection;

/**
 * An evaluation's results as CSV (architecture §7, docs/api.md "Export"): one row per answer in the evaluation,
 * case by case and run by run, with each criterion's score, the comment and who wrote it.
 *
 * - RFC 4180: comma, double quotes around a cell with a comma, quote or line break, quotes doubled, CRLF; UTF-8 with
 *   a BOM, so spreadsheets read the accents.
 * - Formula injection: a cell of text from users that starts with =, +, -, @, tab or CR gets a leading ', so a
 *   spreadsheet shows it as text. The numbers the app writes (1–5 scores) stay numbers.
 * - Streamed from a server-side cursor, a batch at a time, so memory stays flat however many answers there are.
 */
final class CsvExport
{
    private const BATCH = 1000;
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Writes the whole CSV to $output, flushing after each batch.
     *
     * @param resource $output
     */
    public function write(Evaluation $evaluation, $output): void
    {
        $criteria = $evaluation->getRubric()->getCriteria();
        fwrite($output, "\u{FEFF}");
        self::line($output, [
            'case_key', 'tags', 'input', 'reference', 'run_letter', 'run_label', 'output',
            ...array_map(static fn (Criterion $criterion): string => $criterion->getData()->name, $criteria),
            'comment', 'comment_by',
        ]);

        $this->connection->transactional(function () use ($evaluation, $criteria, $output): void {
            // The id is the evaluation's own UUID, quoted: a cursor's query takes no bound parameters.
            $this->connection->executeStatement(
                'DECLARE results_export NO SCROLL CURSOR FOR
                SELECT c.case_key, c.tags::text AS tags, c.input, c.reference, r.letter, run.label, a.output, i.comment,
                    u.display_name,
                    (SELECT json_object_agg(s.criterion_id, json_build_array(s.outcome, s.value, s.error_code))
                    FROM score s WHERE s.evaluation_item_id = i.id)::text AS scores
                FROM evaluation_item i
                JOIN answer a ON a.id = i.answer_id
                JOIN test_case c ON c.id = a.test_case_id
                JOIN run ON run.id = a.run_id
                JOIN evaluation_run r ON r.evaluation_id = i.evaluation_id AND r.run_id = a.run_id
                LEFT JOIN users u ON u.id = i.comment_by_id
                WHERE i.evaluation_id = '.$this->connection->quote($evaluation->getId()->toRfc4122()).'
                ORDER BY c.position, r.letter',
            );
            while ([] !== $rows = $this->connection->fetchAllAssociative('FETCH '.self::BATCH.' FROM results_export')) {
                foreach ($rows as $row) {
                    /** @var list<string> $tags */
                    $tags = json_decode((string) $row['tags'], true, flags: \JSON_THROW_ON_ERROR);
                    /** @var array<string, array{string, int|null, string|null}> $scores */
                    $scores = null === $row['scores'] ? [] : json_decode((string) $row['scores'], true, flags: \JSON_THROW_ON_ERROR);
                    self::line($output, [
                        (string) $row['case_key'],
                        implode('|', $tags),
                        (string) $row['input'],
                        (string) $row['reference'],
                        (string) $row['letter'],
                        (string) $row['label'],
                        (string) $row['output'],
                        ...array_map(static fn (Criterion $criterion): int|string => self::score($criterion, $scores[$criterion->getId()->toRfc4122()] ?? null), $criteria),
                        (string) $row['comment'],
                        (string) $row['display_name'],
                    ]);
                }
                fflush($output);
                flush();
            }
            $this->connection->executeStatement('CLOSE results_export');
        });
    }

    /**
     * One CSV line, RFC 4180, with formula injection neutralised in the text cells.
     *
     * @param resource               $output
     * @param list<string|int|float> $cells
     */
    public static function line($output, array $cells): void
    {
        fputcsv($output, array_map(self::cell(...), $cells), ',', '"', '', "\r\n");
    }

    /** Text that a spreadsheet would read as a formula gets a leading '; numbers the app writes stay numbers. */
    public static function cell(string|int|float $value): string|int|float
    {
        return \is_string($value) && '' !== $value && \in_array($value[0], self::FORMULA_STARTS, true) ? "'".$value : $value;
    }

    /**
     * A score as the export writes it: the value on 1–5 (a number), pass or fail, check_error:<code>, or empty while
     * there is none.
     *
     * @param array{string, int|null, string|null}|null $score outcome, value, error code
     */
    private static function score(Criterion $criterion, ?array $score): int|string
    {
        if (null === $score) {
            return '';
        }
        [$outcome, $value, $error] = $score;
        if ('check_error' === $outcome || null === $value) {
            return 'check_error:'.($error ?? 'unknown');
        }

        return CriterionKind::Scale1To5 === $criterion->getData()->kind ? $value : (1 === $value ? 'pass' : 'fail');
    }
}
