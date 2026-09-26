<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StoryVideoJobStatus: string
{
    case Queued = 'queued';
    case Submitted = 'submitted';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public static function normalize(string $raw): self
    {
        $value = strtolower(trim($raw));

        return match ($value) {
            'queued', 'pending', 'created' => self::Queued,
            'submitted', 'accepted', 'dispatched' => self::Submitted,
            'processing', 'running', 'in_progress', 'generating' => self::Processing,
            'completed', 'succeeded', 'success', 'done' => self::Completed,
            'failed', 'error', 'errored' => self::Failed,
            'cancelled', 'canceled', 'aborted' => self::Cancelled,
            default => self::Failed,
        };
    }
}
