<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * The voice, model and language a single narration request will use.
 */
final readonly class StoryVoiceProfile
{
    public function __construct(
        public string $language,
        public string $model,
        public ?string $voiceId,
        public bool $sendLanguageCode,
        public string $voiceSource,
        public bool $modelVerified,
    ) {}
}
