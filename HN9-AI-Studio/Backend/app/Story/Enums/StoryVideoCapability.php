<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Story video capabilities. Later sprints request these keys; they do not
 * name a vendor.
 */
enum StoryVideoCapability: string
{
    use InteractsWithEnum;

    case TextToVideo = 'text_to_video';
    case ImageToVideo = 'image_to_video';
    case ReferenceToVideo = 'reference_to_video';
    case VideoEdit = 'video_edit';
    case VideoExtend = 'video_extend';
    case Audio = 'audio';

    public function label(): string
    {
        return match ($this) {
            self::TextToVideo => 'Text to Video',
            self::ImageToVideo => 'Image to Video',
            self::ReferenceToVideo => 'Reference to Video',
            self::VideoEdit => 'Video Edit',
            self::VideoExtend => 'Video Extend',
            self::Audio => 'Audio',
        };
    }
}
