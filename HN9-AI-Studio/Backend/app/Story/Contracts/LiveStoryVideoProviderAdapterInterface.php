<?php

declare(strict_types=1);

namespace App\Story\Contracts;

/**
 * An adapter that talks to a real, credentialed provider. Catalog adapters
 * never implement this, so they can never start a generation or count as
 * a connected provider.
 */
interface LiveStoryVideoProviderAdapterInterface extends StoryVideoProviderAdapterInterface
{
    /**
     * Stable vendor slug used for private storage and technical details.
     */
    public function vendor(): string;
}
