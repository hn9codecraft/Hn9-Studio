<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reusable rule validating that a value is a valid backing value of a given
 * backed enum. Keeps allowed-value lists defined once (in the enum) rather than
 * duplicated across form requests.
 */
final readonly class EnumValue implements ValidationRule
{
    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    public function __construct(private string $enum) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute must be a valid value.');

            return;
        }

        if ($this->enum::tryFrom($value) === null) {
            $allowed = implode(', ', array_map(
                static fn (\BackedEnum $case): string => (string) $case->value,
                $this->enum::cases(),
            ));

            $fail("The :attribute must be one of: {$allowed}.");
        }
    }
}
