<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Concerns\HasUuid;
use Illuminate\Support\Str;

/**
 * UUID helpers. Centralises generation and validation so the rest of the
 * codebase never touches the underlying library directly (matches the
 * behaviour of the {@see HasUuid} model trait).
 */
final class Uuid
{
    /**
     * Generate a new v4 UUID string.
     */
    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Whether the given value is a syntactically valid UUID.
     */
    public static function isValid(?string $value): bool
    {
        return $value !== null && Str::isUuid($value);
    }
}
