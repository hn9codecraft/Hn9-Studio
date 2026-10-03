<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Story\Contracts\StorySceneAssemblyServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one scene assembly. It does not call a video provider.
 */
class ProcessStorySceneAssembly implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 930;

    public function __construct(public int $assemblyId)
    {
        $this->onQueue((string) config('story_video.queue.name', 'default'));
        $this->timeout = max(60, (int) config('story_video.ffmpeg.timeout_seconds', 900) + 30);
    }

    public function handle(StorySceneAssemblyServiceInterface $assemblies): void
    {
        $assemblies->execute($this->assemblyId);
    }
}
