<?php

declare(strict_types=1);

namespace App\AI\Support;

/**
 * Normalisation helpers shared by the providers' configuration objects.
 *
 * Configuration reaches the adapters from `config/ai.php` (and therefore the
 * environment), where list and map values arrive as loosely typed arrays — often
 * from a comma-separated environment variable. These helpers coerce them into
 * the strict shapes the typed settings objects declare, so each provider's
 * config class states its own contract rather than repeating the same filtering.
 *
 * Nothing here knows about any vendor, and no value is defaulted or invented.
 */
final class ConfigNormalizer
{
    private function __construct() {}

    /**
     * A list of non-empty, trimmed strings.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * A string keyed map of non-empty string values (e.g. HTTP headers).
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $name = is_string($key) ? trim($key) : '';
            $entry = is_string($item) || is_int($item) || is_float($item) ? trim((string) $item) : '';

            if ($name !== '' && $entry !== '') {
                $map[$name] = $entry;
            }
        }

        return $map;
    }

    /**
     * A name => value map accepted in either representation: an array declared
     * in `config/ai.php`, or a `name:value,name:value` string, which is how a
     * map survives a single environment variable.
     *
     * @return array<string, string>
     */
    public static function keyedMap(mixed $value, string $pairSeparator = ':'): array
    {
        if (! is_string($value)) {
            return self::stringMap($value);
        }

        $map = [];

        foreach (self::stringList(explode(',', $value)) as $pair) {
            $parts = explode($pairSeparator, $pair, 2);

            if (count($parts) === 2) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }

        return self::stringMap($map);
    }

    /**
     * A trimmed string, or null when absent/blank.
     */
    public static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A positive integer, or null when absent or not a positive number.
     */
    public static function positiveInt(mixed $value): ?int
    {
        if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value)))) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
