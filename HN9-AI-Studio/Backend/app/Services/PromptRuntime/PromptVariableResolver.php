<?php

declare(strict_types=1);

namespace App\Services\PromptRuntime;

use App\Contracts\Services\PromptRuntime\PromptVariableResolverInterface;
use Illuminate\Support\Str;

/**
 * Replaces template variables in a prompt string while validating placeholders.
 */
final readonly class PromptVariableResolver implements PromptVariableResolverInterface
{
    public function resolve(string $template, array $variables = []): string
    {
        $placeholders = $this->placeholders($template);

        foreach ($placeholders as $placeholder) {
            $key = $this->normalize($placeholder);

            if (! array_key_exists($key, $variables)) {
                throw new \InvalidArgumentException("Missing prompt variable: {$placeholder}");
            }

            $value = $variables[$key];
            $template = Str::replace('{{'.$placeholder.'}}', $this->stringify($value), $template);
        }

        return $template;
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn ($item) => is_scalar($item) ? (string) $item : json_encode($item, JSON_THROW_ON_ERROR), $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function placeholders(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function normalize(string $value): string
    {
        return str_replace(['-', ' '], '_', trim($value));
    }
}
