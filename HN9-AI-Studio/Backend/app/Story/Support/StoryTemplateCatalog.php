<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Enums\StoryBibleAspectRatio;
use App\Story\Exceptions\StoryQuickStartException;

/**
 * Read-only story templates. User text is substituted as data, never as instructions.
 */
final class StoryTemplateCatalog
{
    /** @var list<string> */
    public const LANGUAGES = ['', 'en', 'hi', 'gu'];

    /** @var list<int> */
    public const DURATIONS = [40, 60, 180];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $templates = [];
        foreach ($this->raw() as $key => $template) {
            $templates[$key] = $this->publicTemplate($key, $template);
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $raw = $this->raw();
        if (! isset($raw[$key]) || ! is_array($raw[$key])) {
            throw StoryQuickStartException::templateNotFound();
        }

        return $raw[$key];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $input
     */
    public function composeIdea(array $template, array $input): string
    {
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        $duration = (int) ($input['duration_seconds'] ?? ($template['defaults']['duration_seconds'] ?? 40));
        $language = (string) ($input['language'] ?? ($template['defaults']['language'] ?? ''));
        $tone = $this->clean((string) ($input['tone'] ?? ($template['defaults']['tone'] ?? '')));
        $topic = $this->clean((string) ($input['topic'] ?? ''));

        $replacements = [
            'duration_label' => $this->durationLabel($duration),
            'language_label' => $this->languageLabel($language),
            'topic' => $topic,
            'tone' => $tone,
        ];
        foreach ($template['fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = $fields[$key] ?? '';
            $replacements[$key] = $this->clean(is_string($value) ? $value : '');
        }

        $scaffold = (string) ($template['idea_scaffold'] ?? '');
        $idea = preg_replace_callback('/\{([a-z0-9_]+)\}/', static function (array $match) use ($replacements): string {
            return $replacements[$match[1]] ?? '';
        }, $scaffold) ?? $scaffold;
        $idea = $this->clean($idea);
        if (mb_strlen($idea) > 10000) {
            $idea = mb_substr($idea, 0, 10000);
        }

        return $idea;
    }

    public function durationLabel(int $seconds): string
    {
        return match ($seconds) {
            40 => '40 sec',
            60 => '1 min',
            180 => '3 min',
            default => $seconds.' sec',
        };
    }

    public function languageLabel(string $language): string
    {
        return match ($language) {
            'en' => 'English',
            'hi' => 'Hindi',
            'gu' => 'Gujarati',
            default => 'Not set',
        };
    }

    /**
     * @return list<string>
     */
    public function aspectRatios(): array
    {
        return StoryBibleAspectRatio::values();
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function publicTemplate(string $key, array $template): array
    {
        unset($template['idea_scaffold']);

        return [
            'key' => $key,
            ...$template,
            'allowed_durations' => self::DURATIONS,
            'aspect_ratios' => $this->aspectRatios(),
            'languages' => self::LANGUAGES,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function raw(): array
    {
        $templates = config('story_templates');

        return is_array($templates) ? $templates : [];
    }

    private function clean(string $value): string
    {
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return trim(is_string($stripped) ? $stripped : $value);
    }
}
