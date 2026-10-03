<?php

declare(strict_types=1);

namespace App\Story\Contracts;

/**
 * A sound adapter that offers named voices. Only names leave the server;
 * vendor voice identifiers stay in configuration.
 */
interface StoryAudioVoiceCatalogInterface
{
    /**
     * @return list<string>
     */
    public function voiceNames(): array;

    public function defaultVoiceName(): ?string;
}
