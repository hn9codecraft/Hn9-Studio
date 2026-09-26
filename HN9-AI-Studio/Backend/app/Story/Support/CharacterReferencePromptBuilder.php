<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Models\StoryCharacter;

/**
 * Builds an image-generation prompt from structured Character Bible fields only.
 */
final class CharacterReferencePromptBuilder
{
    public function build(StoryCharacter $character): string
    {
        $lines = [
            'Create a clean character reference portrait for visual continuity.',
            'Do not invent unrelated details. Preserve only the attributes below.',
            'Character name: '.$character->name,
        ];

        $fields = [
            'Short description' => $character->short_description,
            'Age' => $character->age,
            'Gender / presentation' => $character->gender_presentation,
            'Appearance' => $character->appearance,
            'Face' => $character->face_description,
            'Hair' => $character->hair,
            'Clothing' => $character->clothing,
            'Personality' => $character->personality,
            'Special identifying details' => $character->special_details,
        ];

        foreach ($fields as $label => $value) {
            $text = is_string($value) ? trim($value) : '';
            if ($text !== '') {
                $lines[] = $label.': '.$text;
            }
        }

        $lines[] = 'Style: neutral studio lighting, clear face visibility, full character reference usable for later scene consistency.';

        return implode("\n", $lines);
    }
}
