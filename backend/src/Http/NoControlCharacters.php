<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects NUL and the other control characters (Unicode category Cc), and invalid UTF-8. PostgreSQL cuts a text off
 * at NUL, and the other controls only hide or garble text. With $multiline, tab, line feed and carriage return are
 * allowed (descriptions, and imported inputs and answers).
 *
 * Error code: control_character.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
final class NoControlCharacters extends Constraint
{
    public const CONTROL_CHARACTER_ERROR = '5d1d0d2c-6f1a-4e57-9a51-1c4f9d0b7e21';

    protected const ERROR_NAMES = [self::CONTROL_CHARACTER_ERROR => 'CONTROL_CHARACTER_ERROR'];

    public string $message = 'This value contains a control character, which is not allowed.';

    /**
     * @param list<string>|null $groups
     */
    public function __construct(public bool $multiline = false, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
