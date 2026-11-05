<?php

declare(strict_types=1);

namespace App\Evaluations\Dto;

use App\Evaluations\Entity\CriterionData;
use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The body of POST and PUT rubrics: the name and the ordered criteria, which a PUT replaces whole. Each criterion's
 * errors come at "criteria[N].field", such as criteria[2].checkParams.pattern (architecture §5).
 */
final readonly class SaveRubricRequest
{
    /**
     * @param array<mixed> $criteria
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Give the rubric a name.', normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        #[NoControlCharacters]
        public string $name = '',

        #[Assert\Count(min: 1, max: CriterionRules::MAX_CRITERIA, minMessage: 'Add at least one criterion.', maxMessage: 'A rubric has at most 30 criteria. Remove some.')]
        #[Assert\Callback([self::class, 'validateCriteria'])]
        public array $criteria = [],
    ) {
    }

    public static function validateCriteria(mixed $criteria, ExecutionContextInterface $context): void
    {
        if (!\is_array($criteria) || \count($criteria) > CriterionRules::MAX_CRITERIA) {
            return; // Count says so
        }
        if (!array_is_list($criteria)) {
            $context->buildViolation('List the criteria in an array, in their order.')->setCode('invalid_type')->addViolation();

            return;
        }
        foreach ($criteria as $index => $criterion) {
            $parsed = CriterionRules::parse($criterion);
            foreach ($parsed instanceof CriterionData ? [] : $parsed as [$field, $code, $message]) {
                $context->buildViolation($message)->atPath('' === $field ? "[$index]" : "[$index].$field")->setCode($code)->addViolation();
            }
        }
    }

    /**
     * @return list<CriterionData> the criteria of a valid request, in order
     */
    public function criteriaData(): array
    {
        return array_map(static function (mixed $criterion): CriterionData {
            $data = CriterionRules::parse($criterion);

            return $data instanceof CriterionData ? $data : throw new \LogicException('Validate the request first.');
        }, array_values($this->criteria));
    }
}
