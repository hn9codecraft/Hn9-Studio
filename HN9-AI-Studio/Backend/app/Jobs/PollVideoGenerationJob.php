<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Services\VideoGenerationServiceInterface;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Server-side Veo operation poll. Does not start a second provider job.
 * On the sync driver it polls once and returns so tests/local do not loop.
 */
class PollVideoGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 10, 20, 40, 60];

    public function __construct(public int $videoId) {}

    public function handle(VideoGenerationServiceInterface $generation): void
    {
        $video = Video::query()->find($this->videoId);

        if ($video === null) {
            return;
        }

        $generation->refresh($video);
        $fresh = $video->fresh() ?? $video;

        if (! $fresh->statusEnum()->isInFlight()) {
            return;
        }

        if ((string) config('queue.default') === 'sync') {
            return;
        }

        $attempt = max(1, $this->attempts());
        $delays = $this->backoff;
        $delay = $delays[min($attempt - 1, count($delays) - 1)];

        $this->release($delay);
    }
}
