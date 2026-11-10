<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Models\StoryCharacter;
use App\Story\Support\StoryCharacterIdentityCard;
use Tests\TestCase;

final class StoryCharacterIdentityCardTest extends TestCase
{
    public function test_identity_card_joins_fields_skips_empties_and_keeps_indic_text(): void
    {
        $character = new StoryCharacter([
            'name' => "Maya\u{0007}",
            'appearance' => 'blue   coat',
            'face_description' => '',
            'hair' => 'કાળા વાળ',
            'clothing' => 'પીળો કોટ',
            'special_details' => 'माया का कम्पास',
        ]);

        $card = StoryCharacterIdentityCard::build($character);

        $this->assertSame('Maya: blue coat; hair: કાળા વાળ; wearing: પીળો કોટ; माया का कम्पास', $card);
        $this->assertLessThanOrEqual(300, mb_strlen($card));
        $this->assertStringNotContainsString("\u{0007}", $card);
    }

    public function test_identity_card_cuts_on_a_word_boundary(): void
    {
        config(['story_character_lock.identity_max_chars' => 24]);
        $character = new StoryCharacter([
            'name' => 'Maya',
            'appearance' => 'very long blue coat with silver buttons',
        ]);

        $card = StoryCharacterIdentityCard::build($character);

        $this->assertLessThanOrEqual(24, mb_strlen($card));
        $this->assertStringEndsNotWith(' ', $card);
        $this->assertStringNotContainsString('buttons', $card);
    }
}
