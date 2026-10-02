<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Optional production workflows a project's Creative Studio is narrowed to.
 * Stored under `projects.settings.studio_modules`; an absent value means the
 * studio shows every section. Values must match the frontend STUDIO_WORKFLOWS.
 */
final class StudioWorkflows
{
    public const SETTINGS_KEY = 'studio_modules';

    public const ALL = ['story', 'images', 'videos', 'audio'];

    /**
     * @param  array<string, mixed>|null  $settings
     * @return list<string>|null
     */
    public static function fromSettings(?array $settings): ?array
    {
        $value = $settings[self::SETTINGS_KEY] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $selected = array_values(array_filter(self::ALL, static fn (string $item): bool => in_array($item, $value, true)));

        return $selected === [] ? null : $selected;
    }

    /**
     * Validates the workflow list inside `settings` as a rule on `settings`
     * itself. Nested `settings.*` rules would make `validated()` drop every
     * other settings key (such as Brand Brain values) on update.
     */
    public static function settingsRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || ($value[self::SETTINGS_KEY] ?? null) === null) {
                return;
            }

            if (! self::isValidList($value[self::SETTINGS_KEY])) {
                $fail('Studio workflows must be a list of distinct values from: '.implode(', ', self::ALL).'.');
            }
        };
    }

    private static function isValidList(mixed $modules): bool
    {
        if (! is_array($modules) || ! array_is_list($modules)) {
            return false;
        }

        foreach ($modules as $item) {
            if (! is_string($item) || ! in_array($item, self::ALL, true)) {
                return false;
            }
        }

        return count($modules) === count(array_unique($modules));
    }
}
