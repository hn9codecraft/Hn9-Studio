<?php

declare(strict_types=1);

namespace App\AI\Config;

/**
 * Type coercion for raw `config/ai.php` arrays.
 *
 * Configuration reaches the platform as `array<string, mixed>`, while every
 * settings object below declares strict types. These helpers perform that
 * narrowing once, so no configuration object repeats the same casting.
 *
 * It reads the array it is given and nothing else — no defaults are invented
 * beyond the fallback the caller supplies.
 */
final readonly class ConfigReader
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function of(array $values): self
    {
        return new self($values);
    }

    /**
     * A nested block as its own reader.
     */
    public function section(string $key): self
    {
        $value = $this->values[$key] ?? [];

        /** @var array<string, mixed> $section */
        $section = is_array($value) ? $value : [];

        return new self($section);
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;

        return $value === null ? $default : (bool) $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * A string value that is legitimately absent (e.g. "use the default store").
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A float value that is legitimately absent (e.g. "no budget").
     */
    public function nullableFloat(string $key): ?float
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * A list of class names, filtered to those that actually exist so a stale
     * entry degrades to "not matched" instead of a fatal error.
     *
     * @return list<class-string>
     */
    public function classList(string $key): array
    {
        $classes = [];

        foreach ($this->stringList($key) as $class) {
            if (class_exists($class) || interface_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * A map of string keys to floats, e.g. routing weights.
     *
     * @return array<string, float>
     */
    public function floatMap(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $name => $entry) {
            if (is_string($name) && is_numeric($entry)) {
                $map[$name] = (float) $entry;
            }
        }

        return $map;
    }

    /**
     * A map of string keys to string lists, e.g. per-capability fallback chains.
     *
     * @return array<string, list<string>>
     */
    public function listMap(string $key): array
    {
        $section = $this->section($key);
        $map = [];

        foreach ($section->keys() as $name) {
            $list = $section->stringList($name);

            if ($list !== []) {
                $map[$name] = $list;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }
}
