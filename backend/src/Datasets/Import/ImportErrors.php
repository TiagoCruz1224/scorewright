<?php

declare(strict_types=1);

namespace App\Datasets\Import;

use App\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The errors of a rejected import, in the 422 import_rejected format of architecture §5: at most 100 are listed, while
 * the counts always cover the whole file. Excerpts only go into the response, which the controller returns without
 * throwing, so they never reach an exception log.
 */
final class ImportErrors
{
    public const MAX_LISTED = 100;
    public const MAX_EXCERPT_LENGTH = 80;

    /** @var list<array<string, int|string|null>> */
    private array $listed = [];
    private int $count = 0;
    /** @var array<int, true> */
    private array $affected = [];
    /** @var array<string, int> */
    private array $countsByCode = [];

    /**
     * @param 'line'|'item' $locationKey "line" for CSV (the header is line 1), "item" for JSON (1-based)
     */
    public function __construct(public readonly string $locationKey)
    {
    }

    /**
     * @param int|null $location the line or item; null for an error about the whole file
     * @param string   $message  how to fix it; never quotes the file (the excerpt does)
     */
    public function add(?int $location, ?string $field, string $code, string $message, string $excerpt = ''): void
    {
        ++$this->count;
        $this->countsByCode[$code] = ($this->countsByCode[$code] ?? 0) + 1;
        if (null !== $location) {
            $this->affected[$location] = true;
        }
        if (\count($this->listed) < self::MAX_LISTED) {
            $this->listed[] = [
                $this->locationKey => $location,
                // A CSV column name comes from the file: make it valid UTF-8 and short, like the excerpt.
                'field' => null === $field ? null : self::shorten($field),
                'code' => $code,
                'message' => $message,
                'excerpt' => self::shorten($excerpt),
            ];
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count;
    }

    private static function shorten(string $text): string
    {
        return mb_substr(mb_scrub($text, 'UTF-8'), 0, self::MAX_EXCERPT_LENGTH);
    }

    public function toResponse(): JsonResponse
    {
        return new ApiProblem(422, 'import_rejected', 'Import rejected: nothing was saved', [
            'errorCount' => $this->count,
            'affectedLines' => \count($this->affected),
            'errorCountsByCode' => $this->countsByCode,
            'truncated' => $this->count > self::MAX_LISTED,
            'errors' => $this->listed,
        ])->toResponse();
    }
}
