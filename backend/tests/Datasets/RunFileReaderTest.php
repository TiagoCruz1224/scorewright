<?php

declare(strict_types=1);

namespace App\Tests\Datasets;

use App\Datasets\Import\ImportedAnswer;
use App\Datasets\Import\ImportErrors;
use App\Datasets\Import\RunFileReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every run-file error code and limit, against the cases of valid.csv (greeting, refund-1, multi). */
final class RunFileReaderTest extends TestCase
{
    private const CASE_IDS = ['greeting' => 'c-greeting', 'refund-1' => 'c-refund-1', 'multi' => 'c-multi'];

    public function testAValidCsvKeepsLineBreaksAndAcceptsAnEmptyOutput(): void
    {
        self::assertEquals([
            new ImportedAnswer('c-greeting', 'Hello there!'),
            new ImportedAnswer('c-refund-1', "Sorry about the delay.\nYour refund is on its way."),
            new ImportedAnswer('c-multi', ''),
        ], self::readFixture('run-valid.csv'));
    }

    public function testARunMayLeaveCasesUnanswered(): void
    {
        self::assertEquals([
            new ImportedAnswer('c-greeting', 'Hi!'),
            new ImportedAnswer('c-refund-1', ''),
        ], self::readFixture('run-partial.json'));
    }

    /**
     * @param list<array{int|null, string|null, string}> $expected [line or item, field, code]
     */
    #[DataProvider('invalidFixtures')]
    public function testAnInvalidFixtureIsRejectedWithTheseErrors(string $fixture, array $expected): void
    {
        self::assertSame($expected, self::errorsOf(self::readFixture($fixture)));
    }

    /**
     * @return iterable<string, array{string, list<array{int|null, string|null, string}>}>
     */
    public static function invalidFixtures(): iterable
    {
        yield 'unknown_case_key: an id the version does not have' => ['run-unknown-case-key.csv', [[3, 'id', 'unknown_case_key']]];
        yield 'duplicate_case_key: two answers to one case' => ['run-duplicate-case-key.json', [[2, 'id', 'duplicate_case_key']]];
        yield 'missing_column: no output column' => ['run-missing-column.csv', [[1, 'answer', 'unknown_column'], [1, 'output', 'missing_column']]];
        yield 'required and invalid_type in JSON' => ['run-required.json', [[1, 'output', 'required'], [2, 'id', 'required'], [3, 'id', 'required'], [4, 'output', 'invalid_type']]];
    }

    public function testTooLongAtOneCharacterOver50000(): void
    {
        $csv = "id,output\ngreeting,".str_repeat('é', 50_000)."\nrefund-1,".str_repeat('é', 50_001)."\n";

        self::assertSame([[3, 'output', 'too_long']], self::errorsOf(self::reader()->read($csv, 'csv', self::CASE_IDS)));
    }

    public function testControlCharactersAreRejectedButLineBreaksAndTabsAreFine(): void
    {
        $json = json_encode([['id' => 'greeting', 'output' => "Line 1\n\tLine 2"], ['id' => 'multi', 'output' => "NUL \0 here"]], \JSON_THROW_ON_ERROR);

        self::assertSame([[2, 'output', 'control_character']], self::errorsOf(self::reader()->read($json, 'json', self::CASE_IDS)));
    }

    public function testTooManyRowsAfter5000Answers(): void
    {
        $caseIds = [];
        foreach (range(1, 5_001) as $case) {
            $caseIds["c$case"] = "id$case";
        }
        $csv = "id,output\n".implode("\n", array_map(static fn (int $case): string => "c$case,Answer", range(1, 5_001)))."\n";

        self::assertSame([[5_002, null, 'too_many_rows']], self::errorsOf(self::reader()->read($csv, 'csv', $caseIds)));
    }

    private static function reader(): RunFileReader
    {
        return new RunFileReader(5_000);
    }

    /**
     * @return list<ImportedAnswer>|ImportErrors
     */
    private static function readFixture(string $name): array|ImportErrors
    {
        $format = str_ends_with($name, '.csv') ? 'csv' : 'json';

        return self::reader()->read((string) file_get_contents(__DIR__.'/fixtures/'.$name), $format, self::CASE_IDS);
    }

    /**
     * @param list<ImportedAnswer>|ImportErrors $result
     *
     * @return list<array{int|null, string|null, string}>
     */
    private static function errorsOf(array|ImportErrors $result): array
    {
        self::assertInstanceOf(ImportErrors::class, $result, 'The file was accepted');
        $problem = json_decode((string) $result->toResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        /** @var list<array{int|null, string|null, string}> $errors */
        $errors = array_map(static fn (array $error): array => [$error['line'] ?? $error['item'], $error['field'], $error['code']], $problem['errors']);

        return $errors;
    }
}
