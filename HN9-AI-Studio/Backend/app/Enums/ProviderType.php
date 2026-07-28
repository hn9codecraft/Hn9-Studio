<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The capability category of an AI provider. Mirrors the `category` column on
 * the `ai_providers` table. Describes *what kind* of provider a record is;
 * it carries no integration behaviour (that lands in a later sprint).
 */
enum ProviderType: string
{
    use InteractsWithEnum;

    case Llm = 'llm';
    case Image = 'image';
    case Video = 'video';
    case Tts = 'tts';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM',
            self::Tts => 'Text-to-Speech',
            default => ucfirst($this->value),
        };
    }
}
