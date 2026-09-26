<?php

declare(strict_types=1);

namespace App\AI\Support;

/**
 * Strips credentials and high-entropy secrets from provider/vendor strings
 * before they reach API responses, logs, or persisted error columns.
 */
final class ProviderErrorSanitizer
{
    /**
     * @var list<string>
     */
    private const PATTERNS = [
        '/sk-[A-Za-z0-9_\-]+/',
        '/AIza[0-9A-Za-z_\-]+/',
        '/Bearer\s+\S+/i',
        '/(?:api[_-]?key|access[_-]?token|secret)["\']?\s*[:=]\s*\S+/i',
        '/[?&](?:key|api_key)=[^&\s#]+/i',
        '/-----BEGIN [A-Z ]+-----[\s\S]*?-----END [A-Z ]+-----/',
    ];

    public static function message(?string $message, string $fallback = 'Provider request failed.'): string
    {
        if ($message === null || trim($message) === '') {
            return $fallback;
        }

        $redacted = $message;

        foreach (self::PATTERNS as $pattern) {
            $redacted = (string) preg_replace($pattern, '[redacted]', $redacted);
        }

        $redacted = trim($redacted);

        if ($redacted === '') {
            return $fallback;
        }

        if (strlen($redacted) > 280) {
            return substr($redacted, 0, 277).'...';
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function context(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);

            if (str_contains($name, 'api_key') || str_contains($name, 'secret') || str_contains($name, 'token') || $name === 'authorization') {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = self::value($value);
        }

        return $clean;
    }

    private static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::message($value, $value);
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return self::context($value);
        }

        return $value;
    }
}
