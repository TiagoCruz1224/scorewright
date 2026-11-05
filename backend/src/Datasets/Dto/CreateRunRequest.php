<?php

declare(strict_types=1);

namespace App\Datasets\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

/** The text fields of the multipart request that imports a run (the file is apart). */
final readonly class CreateRunRequest
{
    public function __construct(
        /** The id of the dataset version the run answers. */
        #[Assert\NotBlank]
        public string $datasetVersion = '',

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        #[NoControlCharacters]
        public string $label = '',

        #[Assert\Length(max: 2000)]
        #[NoControlCharacters(multiline: true)]
        public string $modelDescription = '',
    ) {
    }
}
