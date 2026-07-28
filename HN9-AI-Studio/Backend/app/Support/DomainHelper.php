<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * General domain helpers shared across services — slugging, locale checks and
 * small value normalisations. Pure functions with no side effects and no
 * database access.
 */
final class DomainHelper
{
    /**
     * Slugify a value for use as a URL/identifier segment.
     */
    public static function slug(string $value): string
    {
        return Str::slug($value);
    }

    /**
     * Produce a unique slug by testing candidates against the given closure,
     * appending -2, -3, … until the closure reports the slug is free.
     *
     * @param  callable(string): bool  $exists  returns true if the slug is taken
     */
    public static function uniqueSlug(string $value, callable $exists): string
    {
        $base = self::slug($value);
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Whether the given locale is a supported content locale (config/hn9.php).
     */
    public static function isSupportedLocale(?string $locale): bool
    {
        /** @var list<string> $locales */
        $locales = config('hn9.locales', ['en']);

        return $locale !== null && in_array($locale, $locales, true);
    }

    /**
     * The default content locale.
     */
    public static function defaultLocale(): string
    {
        /** @var list<string> $locales */
        $locales = config('hn9.locales', ['en']);

        return $locales[0] ?? 'en';
    }
}
