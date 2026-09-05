<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Kind of studio project asset. Separate from pipeline {@see AssetType}.
 */
enum ProjectAssetType: string
{
    use InteractsWithEnum;

    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Other = 'other';
}
