<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StoryVideoInputType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case ReferenceImage = 'reference_image';
    case ReferenceVideo = 'reference_video';
    case Audio = 'audio';
}
