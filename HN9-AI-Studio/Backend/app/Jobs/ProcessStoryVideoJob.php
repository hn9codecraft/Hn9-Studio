<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryVideoJobRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued submit, poll and download for one Story video job. An accepted
 * operation is polled, never restarted. On the sync driver it runs one step
 * and returns so tests/local do not loop.
 */
class ProcessStoryVideoJob implements ShouldQueue
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

    public function __construct(public int $storyVideoJobId)
    {
        $this->onQueue((string) config('story_video.queue.name', 'default'));
    }

    public function handle(StoryVideoJobRunner $runner): void
    {
        $job = StoryVideoGenerationJob::query()->find($this->storyVideoJobId);

        if ($job === null) {
            return;
        }

        try {
            $job = $runner->step($job);
        } catch (StoryVideoEngineException) {
            return;
        }

        if (! $runner->inFlight($job) || (string) $job->operation_id === '') {
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
