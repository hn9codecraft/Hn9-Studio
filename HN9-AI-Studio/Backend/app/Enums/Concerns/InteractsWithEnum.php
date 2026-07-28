<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared helpers for backed string enums across the domain. Provides the
 * value/name lists and select-friendly option maps used by validation rules,
 * API resources and form requests — so an enum is the single source of truth
 * for its allowed values.
 *
 * @mixin \BackedEnum
 */
trait InteractsWithEnum
{
    /**
     * All backing values of the enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => (string) $case->value, self::cases());
    }

    /**
     * All case names of the enum.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->name, self::cases());
    }

    /**
     * A value => label map suitable for select inputs and API metadata.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Resolve an enum case from a nullable value without throwing.
     */
    public static function tryFromValue(int|string|null $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value);
    }

    /**
     * Whether the given value maps to a valid case.
     */
    public static function isValid(int|string|null $value): bool
    {
        return $value !== null && self::tryFrom($value) !== null;
    }

    /**
     * Human-readable label for the case. Defaults to a title-cased value;
     * enums may override for bespoke wording.
     */
    public function label(): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', (string) $this->value));
    }
}
