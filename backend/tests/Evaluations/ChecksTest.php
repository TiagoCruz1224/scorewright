<?php

declare(strict_types=1);

namespace App\Tests\Evaluations;

use App\Evaluations\Checks\Checks;
use App\Evaluations\Entity\CheckType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The automatic checks as pure functions (architecture §7): each type, with Unicode, case, white space and empty answers. */
final class ChecksTest extends TestCase
{
    /**
     * @param array<string, mixed> $params as CriterionRules stores them
     */
    #[DataProvider('cases')]
    public function testTheCheckGivesItsResult(CheckType $type, array $params, string $output, ?string $reference, bool|string $expected): void
    {
        self::assertSame($expected, Checks::run($type, $params, $output, $reference));
    }

    /**
     * @return iterable<string, array{CheckType, array<string, mixed>, string, string|null, bool|string}>
     */
    public static function cases(): iterable
    {
        $exact = ['ignoreCase' => false];
        $exactIgnoringCase = ['ignoreCase' => true];
        yield 'exact_match: equal' => [CheckType::ExactMatch, $exact, 'Paris', 'Paris', true];
        yield 'exact_match: spaces and line breaks at the ends do not count' => [CheckType::ExactMatch, $exact, "  Paris \n", "\tParis", true];
        yield 'exact_match: Unicode spaces at the ends do not count' => [CheckType::ExactMatch, $exact, "\u{00A0}Paris\u{3000}", 'Paris', true];
        yield 'exact_match: spaces inside count' => [CheckType::ExactMatch, $exact, 'New  York', 'New York', false];
        yield 'exact_match: case counts by default' => [CheckType::ExactMatch, $exact, 'paris', 'Paris', false];
        yield 'exact_match: ignoreCase' => [CheckType::ExactMatch, $exactIgnoringCase, 'PARIS', 'paris', true];
        yield 'exact_match: ignoreCase with accented capitals' => [CheckType::ExactMatch, $exactIgnoringCase, 'ÉCOLE ÇA', 'école ça', true];
        yield 'exact_match: accents count' => [CheckType::ExactMatch, $exactIgnoringCase, 'ecole', 'école', false];
        yield 'exact_match: an empty answer' => [CheckType::ExactMatch, $exact, '', 'Paris', false];
        yield 'exact_match: an answer of spaces against its reference' => [CheckType::ExactMatch, $exact, '   ', 'Paris', false];
        yield 'exact_match: no reference is an error, not a failure' => [CheckType::ExactMatch, $exact, 'Paris', null, Checks::MISSING_REFERENCE];
        yield 'exact_match: no reference and an empty answer' => [CheckType::ExactMatch, $exact, '', null, Checks::MISSING_REFERENCE];

        $refund = ['text' => 'refund', 'ignoreCase' => false];
        $refundIgnoringCase = ['text' => 'refund', 'ignoreCase' => true];
        yield 'contains: found' => [CheckType::Contains, $refund, 'Your refund is on its way.', null, true];
        yield 'contains: not found' => [CheckType::Contains, $refund, 'Your order is on its way.', null, false];
        yield 'contains: case counts by default' => [CheckType::Contains, $refund, 'Refund sent.', null, false];
        yield 'contains: ignoreCase' => [CheckType::Contains, $refundIgnoringCase, 'REFUND SENT.', null, true];
        yield 'contains: ignoreCase with Unicode' => [CheckType::Contains, ['text' => 'aprovação', 'ignoreCase' => true], 'Pedido: APROVAÇÃO concluída', null, true];
        yield 'contains: Unicode, case counting' => [CheckType::Contains, ['text' => 'aprovação', 'ignoreCase' => false], 'APROVAÇÃO', null, false];
        yield 'contains: the reference does not matter' => [CheckType::Contains, $refund, 'A refund', 'Something else', true];
        yield 'contains: an empty answer' => [CheckType::Contains, $refund, '', null, false];

        yield 'not_contains: absent' => [CheckType::NotContains, $refund, 'Thank you.', null, true];
        yield 'not_contains: present' => [CheckType::NotContains, $refund, 'No refund today.', null, false];
        yield 'not_contains: ignoreCase' => [CheckType::NotContains, $refundIgnoringCase, 'REFUND', null, false];
        yield 'not_contains: case counts by default' => [CheckType::NotContains, $refund, 'REFUND', null, true];
        yield 'not_contains: an empty answer' => [CheckType::NotContains, $refund, '', null, true];

        $regex = static fn (string $pattern, bool $ignoreCase = false): array => ['pattern' => $pattern, 'ignoreCase' => $ignoreCase];
        yield 'regex: matches' => [CheckType::Regex, $regex('^Order #[0-9]{6}$'), 'Order #123456', null, true];
        yield 'regex: does not match' => [CheckType::Regex, $regex('^Order #[0-9]{6}$'), 'Order #12345', null, false];
        yield 'regex: anywhere in the answer unless anchored' => [CheckType::Regex, $regex('refund'), 'We sent a refund.', null, true];
        yield 'regex: case counts by default' => [CheckType::Regex, $regex('^hello'), 'HELLO there', null, false];
        yield 'regex: ignoreCase' => [CheckType::Regex, $regex('^hello', true), 'HELLO there', null, true];
        yield 'regex: Unicode properties' => [CheckType::Regex, $regex('^\p{Lu}'), 'Ébano', null, true];
        yield 'regex: a dot is one character, not one byte' => [CheckType::Regex, $regex('^.$'), 'é', null, true];
        yield 'regex: a slash needs no escaping' => [CheckType::Regex, $regex('^a/b$'), 'a/b', null, true];
        yield 'regex: PCRE verbs are allowed' => [CheckType::Regex, $regex('a(*SKIP)(*FAIL)|b'), 'ab', null, true];
        yield 'regex: an empty answer' => [CheckType::Regex, $regex('^$'), '', null, true];
        yield 'regex: an empty answer that does not match' => [CheckType::Regex, $regex('x'), '', null, false];
        yield 'regex: an answer that is not UTF-8 is an error' => [CheckType::Regex, $regex('a'), "a\xFF", null, Checks::REGEX_ERROR];
        yield 'regex: a recursion that never ends is an error' => [CheckType::Regex, $regex('(?R)'), 'a', null, Checks::REGEX_ERROR];

        $length = static fn (?int $min, ?int $max): array => ['min' => $min, 'max' => $max];
        yield 'length: at both limits' => [CheckType::Length, $length(3, 3), 'abc', null, true];
        yield 'length: below the minimum' => [CheckType::Length, $length(4, null), 'abc', null, false];
        yield 'length: above the maximum' => [CheckType::Length, $length(null, 2), 'abc', null, false];
        yield 'length: characters, not bytes' => [CheckType::Length, $length(null, 3), 'ção', null, true];
        yield 'length: spaces count' => [CheckType::Length, $length(3, null), ' a ', null, true];
        yield 'length: an empty answer, minimum 0' => [CheckType::Length, $length(0, 10), '', null, true];
        yield 'length: an empty answer, minimum 1' => [CheckType::Length, $length(1, null), '', null, false];

        yield 'valid_json: an object' => [CheckType::ValidJson, [], '{"refund": true}', null, true];
        yield 'valid_json: an array' => [CheckType::ValidJson, [], '[1, 2]', null, true];
        yield 'valid_json: a string is JSON too' => [CheckType::ValidJson, [], '"refund"', null, true];
        yield 'valid_json: white space around it' => [CheckType::ValidJson, [], " \n{\"a\": 1}\n", null, true];
        yield 'valid_json: Unicode' => [CheckType::ValidJson, [], '{"cidade": "São Paulo"}', null, true];
        yield 'valid_json: keys without quotes' => [CheckType::ValidJson, [], '{refund: true}', null, false];
        yield 'valid_json: text after it' => [CheckType::ValidJson, [], '{"a": 1} done', null, false];
        yield 'valid_json: an empty answer' => [CheckType::ValidJson, [], '', null, false];
    }

    /** Catastrophic backtracking: PCRE's backtrack limit stops it, and the check reports it instead of hanging. */
    public function testCatastrophicBacktrackingIsARegexErrorInMilliseconds(): void
    {
        $answer = str_repeat('a', 30_000).'!';

        $start = hrtime(true);
        $result = Checks::regex($answer, '(a+)+$', false);
        $milliseconds = (hrtime(true) - $start) / 1e6;

        self::assertSame(Checks::REGEX_ERROR, $result);
        self::assertLessThan(500, $milliseconds);
    }

    public function testAMissingParameterIsAnExceptionNotAResult(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Checks::run(CheckType::Contains, ['ignoreCase' => false], 'refund', null);
    }
}
