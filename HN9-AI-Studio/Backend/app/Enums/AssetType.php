<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The kind of generated media asset. Mirrors the `type` column on the
 * `generated_assets` table.
 */
enum AssetType: string
{
    use InteractsWithEnum;

    case Image = 'image';
    case Video = 'video';
    case Voice = 'voice';
    case Thumbnail = 'thumbnail';

    /**
     * The logical storage disk (config/hn9.php disks) this asset type lives on.
     */
    public function disk(): string
    {
        return match ($this) {
            self::Image, self::Thumbnail => 'images',
            self::Video => 'videos',
            self::Voice => 'voice',
        };
    }

    /**
     * The broad media category this asset maps to.
     */
    public function mediaType(): MediaType
    {
        return match ($this) {
            self::Image, self::Thumbnail => MediaType::Image,
            self::Video => MediaType::Video,
            self::Voice => MediaType::Audio,
        };
    }
}
