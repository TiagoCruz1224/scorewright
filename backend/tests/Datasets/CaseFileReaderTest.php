<?php

declare(strict_types=1);

namespace App\Tests\Datasets;

use App\Datasets\Import\CaseFileReader;
use App\Datasets\Import\ImportedCase;
use App\Datasets\Import\ImportErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every import error code and limit (architecture §4 and §9) with a fixture: small ones are files in fixtures/, large
 * ones are generated here.
 */
final class CaseFileReaderTest extends TestCase
{
    public function testAValidCsvKeepsQuotesLineBreaksTagsAndMetadata(): void
    {
        $cases = self::readFixture('valid.csv');
        self::assertIsArray($cases);

        self::assertSame(['greeting', 'refund-1', 'multi'], array_map(static fn (ImportedCase $case): string => $case->caseKey, $cases));
        self::assertEquals(new ImportedCase('greeting', 'Say hello', 'Hello!', ['smalltalk', 'short'], '{"lang":"en"}'), $cases[0]);
        self::assertEquals(new ImportedCase('refund-1', "I want a refund.\nMy order is late, and I am \"angry\".", null, ['billing'], '{}'), $cases[1]);
        self::assertEquals(new ImportedCase('multi', "Tabs\tand line breaks are fine", "Line 1\nLine 2", [], '{}'), $cases[2]);
    }

    public function testACsvWithABomSemicolonsAndCrlfNumbersTheCasesByLine(): void
    {
        $cases = self::readFixture('valid-semicolon-bom-crlf.csv');

        // Line 3 is blank: the cases are on lines 2 and 4, so their keys are 1 and 3.
        self::assertEquals([
            new ImportedCase('1', 'First question', null, ['a', 'b'], '{}'),
            new ImportedCase('3', 'Second question', null, [], '{}'),
        ], $cases);
    }

    public function testAValidJsonFile(): void
    {
        self::assertEquals([
            new ImportedCase('greeting', 'Say hello', 'Hello!', ['smalltalk', 'short'], '{"lang":"en"}'),
            new ImportedCase('42', 'Numeric ids become strings', null, [], '{}'),
            new ImportedCase('nested', "Line 1\nLine 2", null, [], '{"a":{"b":[1,2.5,true,null]}}'),
        ], self::readFixture('valid.json'));
    }

    public function testJsonWithoutIdsNumbersTheItemsAndCleansTheTags(): void
    {
        self::assertEquals([
            new ImportedCase('1', 'First', null, [], '{}'),
            new ImportedCase('2', 'Second', null, ['billing'], '{}'),
        ], self::readFixture('valid-no-ids.json'));
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
        yield 'empty_file: no bytes' => ['empty.csv', [[null, null, 'empty_file']]];
        yield 'empty_file: a header only' => ['header-only.csv', [[null, null, 'empty_file']]];
        yield 'empty_file: an empty array' => ['empty-array.json', [[null, null, 'empty_file']]];
        yield 'missing_column' => ['missing-column.csv', [[1, 'input', 'missing_column']]];
        yield 'unknown_column' => ['unknown-column.csv', [[1, 'prompt', 'unknown_column']]];
        yield 'duplicate_column' => ['duplicate-column.csv', [[1, 'input', 'duplicate_column']]];
        yield 'invalid_csv: a quote never closed' => ['invalid-csv-quote.csv', [[3, null, 'invalid_csv']]];
        yield 'invalid_csv: more values than columns' => ['invalid-csv-values.csv', [[2, null, 'invalid_csv']]];
        yield 'invalid_utf8 in a CSV value' => ['invalid-utf8.csv', [[3, 'input', 'invalid_utf8']]];
        yield 'invalid_utf8 in a JSON file' => ['invalid-utf8.json', [[null, null, 'invalid_utf8']]];
        yield 'invalid_json: not an array' => ['not-an-array.json', [[null, null, 'invalid_json']]];
        yield 'invalid_json: a syntax error' => ['invalid.json', [[null, null, 'invalid_json']]];
        yield 'invalid_type' => ['invalid-type.json', [[1, 'input', 'invalid_type'], [2, null, 'invalid_type'], [3, 'tags', 'invalid_type'], [4, 'reference', 'invalid_type']]];
        yield 'unknown_field' => ['unknown-field.json', [[1, 'expected', 'unknown_field']]];
        yield 'required in CSV: an id and an input' => ['required.csv', [[3, 'id', 'required'], [4, 'input', 'required']]];
        yield 'required in JSON: an id and an input' => ['required.json', [[2, 'id', 'required'], [3, 'input', 'required']]];
        yield 'duplicate_case_key in CSV' => ['duplicate-case-key.csv', [[4, 'id', 'duplicate_case_key']]];
        yield 'duplicate_case_key in JSON: 1 and "1"' => ['duplicate-case-key.json', [[2, 'id', 'duplicate_case_key']]];
        yield 'too_many_tags: 20 pass, 21 do not' => ['too-many-tags.csv', [[3, 'tags', 'too_many_tags']]];
        yield 'tag_too_long: 50 characters pass, 51 do not' => ['tag-too-long.csv', [[3, 'tags', 'tag_too_long']]];
        yield 'invalid_metadata in CSV' => ['invalid-metadata.csv', [[2, 'metadata', 'invalid_metadata'], [3, 'metadata', 'invalid_metadata']]];
        yield 'invalid_metadata in JSON' => ['invalid-metadata.json', [[1, 'metadata', 'invalid_metadata'], [2, 'metadata', 'invalid_metadata']]];
        yield 'control_character in CSV' => ['control-character.csv', [[2, 'input', 'control_character'], [3, 'tags', 'control_character'], [4, 'reference', 'control_character']]];
        yield 'control_character in JSON, NUL in metadata included' => ['control-character.json', [[1, 'input', 'control_character'], [2, 'metadata', 'control_character'], [3, 'input', 'control_character']]];
    }

    public function testTooLongAtOneCharacterOverEachLimit(): void
    {
        $csv = "id,input,reference\n"
            .str_repeat('k', 100).','.str_repeat('é', 20_000).','.str_repeat('r', 20_000)."\n"
            .str_repeat('k', 101).','.str_repeat('é', 20_001).','.str_repeat('r', 20_001)."\n";

        self::assertSame([[3, 'id', 'too_long'], [3, 'input', 'too_long'], [3, 'reference', 'too_long']], self::errorsOf(self::reader()->read($csv, 'csv')));
    }

    public function testMetadataTooLargeAtOneByteOver10KiB(): void
    {
        // {"k":"…"} is 8 bytes around the value.
        $json = json_encode([
            ['input' => 'a', 'metadata' => ['k' => str_repeat('x', 10_232)]],
            ['input' => 'b', 'metadata' => ['k' => str_repeat('x', 10_233)]],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([[2, 'metadata', 'metadata_too_large']], self::errorsOf(self::reader()->read($json, 'json')));
    }

    public function testTooManyRowsAfter5000Cases(): void
    {
        $csv = "input\n".implode("\n", array_map(static fn (int $case): string => "Case $case", range(1, 5_000)))."\n";
        $cases = self::reader()->read($csv, 'csv');
        self::assertIsArray($cases);
        self::assertCount(5_000, $cases);

        self::assertSame([[5_002, null, 'too_many_rows']], self::errorsOf(self::reader()->read($csv."Case 5001\n", 'csv')));

        $json = json_encode(array_map(static fn (int $case): array => ['input' => "Case $case"], range(1, 5_001)), \JSON_THROW_ON_ERROR);
        self::assertSame([[5_001, null, 'too_many_rows']], self::errorsOf(self::reader()->read($json, 'json')));
    }

    public function testMetadataNestedDeeperThan30LevelsIsRejected(): void
    {
        $metadata = static fn (int $levels): string => str_repeat('{"a": ', $levels - 1).'{"leaf": 1}'.str_repeat('}', $levels - 1);
        $json = static fn (int $levels): string => '[{"input": "a", "metadata": '.$metadata($levels).'}]';
        $csv = static fn (int $levels): string => "input,metadata\na,\"".str_replace('"', '""', $metadata($levels))."\"\n";

        self::assertIsArray(self::reader()->read($json(30), 'json'));
        self::assertSame([[null, null, 'invalid_json']], self::errorsOf(self::reader()->read($json(31), 'json')));
        self::assertIsArray(self::reader()->read($csv(30), 'csv'));
        self::assertSame([[2, 'metadata', 'invalid_metadata']], self::errorsOf(self::reader()->read($csv(31), 'csv')));
    }

    public function testAtMost100ErrorsAreListedWhileTheCountsCoverTheWholeFile(): void
    {
        // 150 lines with two errors each, then one with a long input.
        $csv = "id,input\n".str_repeat(",\n", 150).'long,'.str_repeat('é', 20_001)."\n";
        $result = self::reader()->read($csv, 'csv');
        self::assertInstanceOf(ImportErrors::class, $result);
        $problem = json_decode((string) $result->toResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['type', 'title', 'status', 'code', 'errorCount', 'affectedLines', 'errorCountsByCode', 'truncated', 'errors'], array_keys($problem));
        self::assertSame(['/problems/import-rejected', 'Import rejected: nothing was saved', 422, 'import_rejected'], [$problem['type'], $problem['title'], $problem['status'], $problem['code']]);
        self::assertSame(301, $problem['errorCount']);
        self::assertSame(151, $problem['affectedLines']);
        self::assertSame(['required' => 300, 'too_long' => 1], $problem['errorCountsByCode']);
        self::assertTrue($problem['truncated']);
        self::assertCount(100, $problem['errors']);
        self::assertSame(['line' => 2, 'field' => 'id', 'code' => 'required'], array_slice($problem['errors'][0], 0, 3));
        self::assertSame(['line', 'field', 'code', 'message', 'excerpt'], array_keys($problem['errors'][0]));

        // The excerpt of the long input (the 301st error is not listed, so read it from a file with just that line).
        $long = self::reader()->read("input\n".str_repeat('é', 20_001)."\n", 'csv');
        self::assertInstanceOf(ImportErrors::class, $long);
        $error = json_decode((string) $long->toResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['errors'][0];
        self::assertSame(str_repeat('é', 80), $error['excerpt']);
        self::assertSame('', $problem['errors'][0]['excerpt']);
    }

    public function testMessagesNeverQuoteTheFile(): void
    {
        $result = self::readFixture('duplicate-case-key.csv');
        self::assertInstanceOf(ImportErrors::class, $result);
        $error = json_decode((string) $result->toResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR)['errors'][0];

        self::assertSame('Another case (line 2) already uses this id. Each id must be unique in the file.', $error['message']);
        self::assertSame('a', $error['excerpt']);
    }

    private static function reader(): CaseFileReader
    {
        return new CaseFileReader(5_000);
    }

    /**
     * @return list<ImportedCase>|ImportErrors
     */
    private static function readFixture(string $name): array|ImportErrors
    {
        $format = str_ends_with($name, '.csv') ? 'csv' : 'json';

        return self::reader()->read((string) file_get_contents(__DIR__.'/fixtures/'.$name), $format);
    }

    /**
     * @param list<ImportedCase>|ImportErrors $result
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
