<?php

declare(strict_types=1);

namespace App\Tests\Evaluations;

use App\Evaluations\Dto\CriterionRules;
use App\Evaluations\Entity\CheckType;
use App\Evaluations\Entity\CriterionData;
use App\Evaluations\Entity\CriterionKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rules for one criterion of a rubric (architecture §4, §7): each kind and each check type, valid and not. */
final class CriterionRulesTest extends TestCase
{
    /**
     * @param array<string, mixed> $criterion
     * @param array<string, mixed> $checkParams the stored parameters, with their defaults
     */
    #[DataProvider('validCriteria')]
    public function testAValidCriterionIsStoredWithItsDefaults(array $criterion, ?CheckType $checkType, ?array $checkParams): void
    {
        $data = CriterionRules::parse($criterion);

        self::assertInstanceOf(CriterionData::class, $data);
        self::assertSame([$checkType, $checkParams], [$data->checkType, $data->checkParams]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, CheckType|null, array<string, mixed>|null}>
     */
    public static function validCriteria(): iterable
    {
        $automatic = static fn (string $type, ?array $parameters = null): array => ['kind' => 'automatic', 'name' => 'Check', 'checkType' => $type] + (null === $parameters ? [] : ['checkParams' => $parameters]);

        yield 'scale_1_5 with guidance and a weight' => [['kind' => 'scale_1_5', 'name' => 'Tone', 'guidance' => "1: rude\n5: warm", 'weight' => 100], null, null];
        yield 'pass_fail with guidance' => [['kind' => 'pass_fail', 'name' => 'Correct', 'guidance' => 'Pass when the facts are right.', 'weight' => null], null, null];
        yield 'exact_match: ignoreCase defaults to false' => [$automatic('exact_match'), CheckType::ExactMatch, ['ignoreCase' => false]];
        yield 'contains' => [$automatic('contains', ['text' => 'refund', 'ignoreCase' => true]), CheckType::Contains, ['text' => 'refund', 'ignoreCase' => true]];
        yield 'not_contains, 500 characters' => [$automatic('not_contains', ['text' => str_repeat('x', 500)]), CheckType::NotContains, ['text' => str_repeat('x', 500), 'ignoreCase' => false]];
        yield 'regex, 500 characters' => [$automatic('regex', ['pattern' => str_repeat('a', 500)]), CheckType::Regex, ['pattern' => str_repeat('a', 500), 'ignoreCase' => false]];
        yield 'regex with a slash and Unicode' => [$automatic('regex', ['pattern' => '^Refund \d+/é', 'ignoreCase' => true]), CheckType::Regex, ['pattern' => '^Refund \d+/é', 'ignoreCase' => true]];
        yield 'length: a maximum only' => [$automatic('length', ['max' => 500]), CheckType::Length, ['min' => null, 'max' => 500]];
        yield 'length: 0 to 50,000' => [$automatic('length', ['min' => 0, 'max' => 50_000]), CheckType::Length, ['min' => 0, 'max' => 50_000]];
        yield 'valid_json with {} as parameters' => [$automatic('valid_json', []), CheckType::ValidJson, []];
    }

    /**
     * @param list<array{string, string}> $expected [field, code]
     */
    #[DataProvider('invalidCriteria')]
    public function testAnInvalidCriterionGetsTheseErrors(mixed $criterion, array $expected): void
    {
        $errors = CriterionRules::parse($criterion);

        self::assertIsArray($errors);
        self::assertSame($expected, array_map(static fn (array $error): array => [$error[0], $error[1]], $errors));
    }

    /**
     * @return iterable<string, array{mixed, list<array{string, string}>}>
     */
    public static function invalidCriteria(): iterable
    {
        $scale = ['kind' => 'scale_1_5', 'name' => 'Tone', 'guidance' => 'How warm it is.'];
        $automatic = static fn (string $type, mixed $parameters = null): array => ['kind' => 'automatic', 'name' => 'Check', 'checkType' => $type, 'checkParams' => $parameters];

        yield 'not an object' => ['Tone', [['', 'invalid_type']]];
        yield 'a list, not an object' => [['scale_1_5', 'Tone'], [['', 'invalid_type']]];
        yield 'empty' => [[], [['kind', 'is_blank'], ['name', 'is_blank']]];
        yield 'an unknown field' => [$scale + ['id' => 'c1'], [['id', 'unknown_field']]];
        yield 'an unknown kind' => [['kind' => 'essay'] + $scale, [['kind', 'no_such_choice']]];
        yield 'the kind is not text' => [['kind' => 5] + $scale, [['kind', 'invalid_type']]];
        yield 'scale_1_5 without guidance' => [['guidance' => '  '] + $scale, [['guidance', 'is_blank']]];
        yield 'pass_fail without guidance' => [['kind' => 'pass_fail', 'name' => 'Correct'], [['guidance', 'is_blank']]];
        yield 'a manual criterion with a check' => [$scale + ['checkType' => 'contains', 'checkParams' => ['text' => 'x']], [['checkType', 'not_allowed'], ['checkParams', 'not_allowed']]];
        yield 'a name of 201 characters' => [['name' => str_repeat('n', 201)] + $scale, [['name', 'too_long']]];
        yield 'a name on two lines' => [['name' => "Tone\nand style"] + $scale, [['name', 'control_character']]];
        yield 'guidance of 5,001 characters' => [['guidance' => str_repeat('g', 5001)] + $scale, [['guidance', 'too_long']]];
        yield 'a weight of 0' => [$scale + ['weight' => 0], [['weight', 'not_in_range']]];
        yield 'a weight over 100' => [$scale + ['weight' => 100.5], [['weight', 'not_in_range']]];
        yield 'a weight as text' => [$scale + ['weight' => '2'], [['weight', 'invalid_type']]];
        yield 'automatic without a check' => [['kind' => 'automatic', 'name' => 'Check'], [['checkType', 'is_blank']]];
        yield 'an unknown check' => [$automatic('similarity'), [['checkType', 'no_such_choice']]];
        yield 'parameters that are not an object' => [$automatic('contains', 'refund'), [['checkParams', 'invalid_type']]];
        yield 'contains without text' => [$automatic('contains', []), [['checkParams.text', 'is_blank']]];
        yield 'contains with 501 characters' => [$automatic('contains', ['text' => str_repeat('x', 501)]), [['checkParams.text', 'too_long']]];
        yield 'ignoreCase that is not a boolean' => [$automatic('contains', ['text' => 'x', 'ignoreCase' => 'yes']), [['checkParams.ignoreCase', 'invalid_type']]];
        yield 'exact_match with a text' => [$automatic('exact_match', ['text' => 'x']), [['checkParams.text', 'unknown_field']]];
        yield 'regex without a pattern' => [$automatic('regex', ['ignoreCase' => true]), [['checkParams.pattern', 'is_blank']]];
        yield 'regex with 501 characters' => [$automatic('regex', ['pattern' => str_repeat('a', 501)]), [['checkParams.pattern', 'too_long']]];
        yield 'regex that does not compile' => [$automatic('regex', ['pattern' => '(abc']), [['checkParams.pattern', 'invalid_regex']]];
        yield 'regex with a control character' => [$automatic('regex', ['pattern' => "a\x01b"]), [['checkParams.pattern', 'control_character']]];
        yield 'length without limits' => [$automatic('length', []), [['checkParams.min', 'is_blank']]];
        yield 'length with the maximum below the minimum' => [$automatic('length', ['min' => 10, 'max' => 5]), [['checkParams.max', 'max_below_min']]];
        yield 'length below 0' => [$automatic('length', ['min' => -1]), [['checkParams.min', 'not_in_range']]];
        yield 'length over 50,000' => [$automatic('length', ['max' => 50_001]), [['checkParams.max', 'not_in_range']]];
        yield 'length that is not a whole number' => [$automatic('length', ['min' => 1.5, 'max' => '9']), [['checkParams.min', 'invalid_type'], ['checkParams.max', 'invalid_type']]];
        yield 'valid_json with a parameter' => [$automatic('valid_json', ['pattern' => 'x']), [['checkParams.pattern', 'unknown_field']]];
    }

    public function testAPatternThatDoesNotCompileSaysWhyInPcresWords(): void
    {
        $message = static function (string $pattern): string {
            $errors = CriterionRules::parse(['kind' => 'automatic', 'name' => 'R', 'checkType' => 'regex', 'checkParams' => ['pattern' => $pattern]]);
            self::assertIsArray($errors);

            return $errors[0][2];
        };

        self::assertStringContainsString('missing closing parenthesis at offset 4', $message('(abc'));
        self::assertStringContainsString('range out of order in character class at offset 3', $message('[z-a]'));
        self::assertStringContainsString('\ at end of pattern', $message('abc\\'));
    }

    public function testTheRegexNeedsNoDelimitersAndHonoursIgnoreCase(): void
    {
        self::assertSame(1, preg_match(CriterionRules::regex('^refund/\d+$', true), 'Refund/42'));
        self::assertSame(0, preg_match(CriterionRules::regex('^refund', false), 'Refund'));
        self::assertSame(1, preg_match(CriterionRules::regex('^é.$', false), 'éa'));
    }

    public function testTheNameIsTrimmedAndTheGuidanceKept(): void
    {
        $data = CriterionRules::parse(['kind' => 'pass_fail', 'name' => '  Correct ', 'guidance' => "Pass when:\n- the facts are right"]);

        self::assertInstanceOf(CriterionData::class, $data);
        self::assertSame([CriterionKind::PassFail, 'Correct', "Pass when:\n- the facts are right", null], [$data->kind, $data->name, $data->guidance, $data->weight]);
    }
}
