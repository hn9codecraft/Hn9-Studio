<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Broad media category for a physical file, derived from its MIME type. Used
 * to classify `media_files` records independently of the generating asset.
 */
enum MediaType: string
{
    use InteractsWithEnum;

    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';

    /**
     * Best-effort classification of a MIME type into a media category.
     */
    public static function fromMimeType(?string $mimeType): self
    {
        if ($mimeType === null) {
            return self::Document;
        }

        return match (true) {
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'video/') => self::Video,
            str_starts_with($mimeType, 'audio/') => self::Audio,
            default => self::Document,
        };
    }
}
