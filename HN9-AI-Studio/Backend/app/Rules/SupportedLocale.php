<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\DomainHelper;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reusable rule validating that a value is one of the studio's supported
 * content locales (config/hn9.php). Mirrors the Prompt Engine language layer.
 */
final class SupportedLocale implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! DomainHelper::isSupportedLocale($value)) {
            /** @var list<string> $locales */
            $locales = config('hn9.locales', ['en']);

            $fail('The :attribute must be a supported locale: '.implode(', ', $locales).'.');
        }
    }
}
