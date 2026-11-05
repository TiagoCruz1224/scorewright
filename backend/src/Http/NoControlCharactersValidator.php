<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NoControlCharactersValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoControlCharacters) {
            throw new UnexpectedTypeException($constraint, NoControlCharacters::class);
        }
        if (null === $value) {
            return;
        }
        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // preg_match() returns false for invalid UTF-8, which is rejected too.
        if (0 !== preg_match($constraint->multiline ? '/[^\P{Cc}\t\n\r]/u' : '/\p{Cc}/u', $value)) {
            $this->context->buildViolation($constraint->message)
                ->setCode(NoControlCharacters::CONTROL_CHARACTER_ERROR)
                ->addViolation();
        }
    }
}
