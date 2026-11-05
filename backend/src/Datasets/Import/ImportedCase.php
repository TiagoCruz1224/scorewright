<?php

declare(strict_types=1);

namespace App\Datasets\Import;

/** A valid case read from a dataset file, ready to insert. */
final readonly class ImportedCase
{
    /**
     * @param list<string> $tags
     * @param string       $metadata a JSON object, "{}" when the file has none
     */
    public function __construct(
        public string $caseKey,
        public string $input,
        public ?string $reference,
        public array $tags,
        public string $metadata,
    ) {
    }
}
