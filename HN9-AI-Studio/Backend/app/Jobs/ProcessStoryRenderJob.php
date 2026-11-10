<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\StoryRenderFinished;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryFinalRender;
use App\Story\Services\StoryRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one story render off the request. The claim is the lock: a second
 * worker that finds the row no longer queued leaves it alone.
 */
class ProcessStoryRenderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $renderId, public string $renderUuid)
    {
        $this->timeout = max(1, (int) config('story_render.queue.timeout', 1200));
        $this->onQueue((string) config('story_render.queue.name', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->renderUuid;
    }

    public function handle(StoryRenderService $renders): void
    {
        $claimed = StoryFinalRender::query()
            ->whereKey($this->renderId)
            ->where('status', StoryVideoJobStatus::Queued->value)
            ->update([
                'status' => StoryVideoJobStatus::Rendering->value,
                'started_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }

        $render = StoryFinalRender::query()->find($this->renderId);
        if (! $render instanceof StoryFinalRender) {
            return;
        }

        try {
            $done = $renders->renderNow($render);
            $this->notify($done);
        } catch (Throwable $exception) {
            $failed = $render->fresh() ?? $render;
            if ($failed->status !== StoryVideoJobStatus::Failed->value) {
                $renders->markFailed($failed, $exception);
                $failed = $failed->fresh() ?? $failed;
            }
            $this->notify($failed);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $render = StoryFinalRender::query()->find($this->renderId);
        if (! $render instanceof StoryFinalRender) {
            return;
        }
        if (! in_array($render->status, StoryRenderService::ACTIVE, true)) {
            return;
        }
        $error = $exception ?? new \RuntimeException('The timeline could not be rendered.');
        app(StoryRenderService::class)->markFailed($render, $error);
        $this->notify($render->fresh() ?? $render);
    }

    private function notify(StoryFinalRender $render): void
    {
        if ($render->requested_by === null) {
            return;
        }
        $user = User::query()->find($render->requested_by);
        if ($user instanceof User) {
            $user->notify(new StoryRenderFinished($render));
        }
    }
}
