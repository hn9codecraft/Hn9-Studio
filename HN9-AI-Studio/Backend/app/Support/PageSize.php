<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Caps list page size so query-string perPage cannot request an unbounded page.
 * Frontend studio lists use 50; provider index already validates max 100.
 */
final class PageSize
{
    public const MAX = 100;

    public static function fromRequest(Request $request, int $default = 15, string $key = 'perPage'): int
    {
        $raw = $request->query($key, $request->query('per_page', $default));
        $value = (int) $raw;

        if ($value < 1) {
            return $default;
        }

        return min($value, self::MAX);
    }
}
