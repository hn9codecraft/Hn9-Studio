<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * A discrete feature an AI provider may declare support for. Used by the
 * registry and capability report to route requests to capable providers.
 */
enum Capability: string
{
    use InteractsWithEnum;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Voice = 'voice';
    case Streaming = 'streaming';
    case FunctionCalling = 'function_calling';

    /**
     * The generation capabilities (excludes cross-cutting features such as
     * streaming and function calling).
     *
     * @return list<self>
     */
    public static function generative(): array
    {
        return [self::Text, self::Image, self::Video, self::Voice];
    }

    public function label(): string
    {
        return match ($this) {
            self::FunctionCalling => 'Function Calling',
            default => ucfirst($this->value),
        };
    }
}
