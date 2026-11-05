<?php

declare(strict_types=1);

namespace App\Evaluations\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST evaluations. The controller then checks that the version, the runs and the rubric are the project's,
 * and that every run answers that version.
 */
final readonly class CreateEvaluationRequest
{
    /** The MVP limit of runs in one evaluation (requirements §7). */
    public const MAX_RUNS = 5;

    /**
     * @param array<mixed> $runs run ids; their order gives the letters A, B, C…
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Give the evaluation a name.', normalizer: 'trim')]
        #[Assert\Length(max: 200)]
        #[NoControlCharacters]
        public string $name = '',

        #[Assert\NotBlank(message: 'Choose the dataset version.')]
        public string $datasetVersion = '',

        #[Assert\Count(min: 1, max: self::MAX_RUNS, minMessage: 'Choose at least one run.', maxMessage: 'An evaluation has at most 5 runs. Remove some.')]
        public array $runs = [],

        #[Assert\NotBlank(message: 'Choose the rubric.')]
        public string $rubric = '',

        /** On unless turned off: the safer choice (UX §3.4). */
        public bool $blind = true,
    ) {
    }
}
