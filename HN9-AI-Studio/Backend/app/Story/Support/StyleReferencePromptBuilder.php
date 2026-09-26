<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Models\StoryStyleBible;

/**
 * Builds an image-generation prompt from structured Style Bible fields only.
 */
final class StyleReferencePromptBuilder
{
    public function build(StoryStyleBible $style): string
    {
        $lines = [
            'Create a clean style reference image for visual continuity.',
            'Do not invent unrelated style attributes. Preserve only the attributes below.',
        ];

        $fields = [
            'Visual style' => $style->visual_style,
            'Animation style' => $style->animation_style,
            'Lighting' => $style->lighting,
            'Camera style' => $style->camera_style,
            'Color direction' => $style->color_direction,
            'Environment style' => $style->environment_style,
            'Mood' => $style->mood,
            'Rendering style' => $style->rendering_style,
            'Visual quality' => $style->visual_quality,
            'Art direction notes' => $style->art_direction_notes,
            'Aspect ratio' => $style->aspect_ratio,
        ];

        foreach ($fields as $label => $value) {
            $text = is_string($value) ? trim($value) : '';
            if ($text !== '') {
                $lines[] = $label.': '.$text;
            }
        }

        $lines[] = 'Output: a representative still that communicates this visual direction for later scene consistency.';

        return implode("\n", $lines);
    }
}
