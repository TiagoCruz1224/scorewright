<?php

declare(strict_types=1);

namespace App\Evaluations\Dto;

use App\Http\NoControlCharacters;
use Symfony\Component\Validator\Constraints as Assert;

/** The body of PUT …/comment: one comment per answer, on several lines if needed; an empty one clears it. */
final readonly class CommentRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Send the comment; an empty one clears it.')]
        #[Assert\Length(max: 5000)]
        #[NoControlCharacters(multiline: true)]
        public ?string $comment = null,
    ) {
    }
}
