<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The generation modality a provider request/response operates in. Aligns with
 * the studio's asset/content taxonomy while remaining specific to the AI
 * provider abstraction.
 */
enum Modality: string
{
    use InteractsWithEnum;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Voice = 'voice';

    /**
     * The capability a provider must declare to serve this modality.
     */
    public function capability(): Capability
    {
        return match ($this) {
            self::Text => Capability::Text,
            self::Image => Capability::Image,
            self::Video => Capability::Video,
            self::Voice => Capability::Voice,
        };
    }
}
