<?php

declare(strict_types=1);

namespace App\Tests\Analytics;

use App\Analytics\CsvExport;
use PHPUnit\Framework\TestCase;

/** A CSV line of the export (architecture §7): RFC 4180, and no cell of text that a spreadsheet would run. */
final class CsvExportTest extends TestCase
{
    public function testTextThatLooksLikeAFormulaGetsAQuoteButTheAppsNumbersStayNumbers(): void
    {
        self::assertSame(
            "'=1+1,'+1,'-1,'@SUM(A1),\"'\tx\",\"'\rx\",-3,4,plain\r\n",
            self::line(['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx", -3, 4, 'plain']),
        );
    }

    public function testQuotesCommasAndLineBreaksAreQuotedAsRfc4180Says(): void
    {
        self::assertSame(
            "\"She said \"\"refund\"\"\",\"a, b\",\"line 1\nline 2\",,café\r\n",
            self::line(['She said "refund"', 'a, b', "line 1\nline 2", '', 'café']),
        );
    }

    /**
     * @param list<string|int|float> $cells
     */
    private static function line(array $cells): string
    {
        $output = fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream');
        CsvExport::line($output, $cells);
        rewind($output);

        return (string) stream_get_contents($output);
    }
}
