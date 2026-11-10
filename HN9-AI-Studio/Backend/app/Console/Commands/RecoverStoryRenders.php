<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessStoryRenderJob;
use App\Models\User;
use App\Notifications\StoryRenderFinished;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryFinalRender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes renders whose worker disappeared, and puts a lost queued render
 * back on the queue once. Mirrors the video-job recovery command, which is
 * not on the scheduler, so this command is not scheduled either.
 */
class RecoverStoryRenders extends Command
{
    protected $signature = 'hn9:story-recover-renders {--dry-run : Report what would change without writing}';

    protected $description = 'Fail interrupted story renders and re-queue ones whose job was lost';

    public function handle(): int
    {
        $minutes = max(1, (int) config('story_render.queue.stale_after_minutes', 30));
        $cutoff = now()->subMinutes($minutes);
        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        $redispatched = 0;

        $rendering = StoryFinalRender::query()
            ->where('status', StoryVideoJobStatus::Rendering->value)
            ->where(static function ($query) use ($cutoff): void {
                $query->where('started_at', '<=', $cutoff)->orWhere(static function ($query) use ($cutoff): void {
                    $query->whereNull('started_at')->where('updated_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->get();

        foreach ($rendering as $render) {
            if (! $render instanceof StoryFinalRender) {
                continue;
            }
            $failed++;
            if ($dry) {
                continue;
            }
            $render->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => 'story_render_failed',
                'error_message' => 'Render was interrupted; please render again',
            ])->save();
            $this->notify($render->fresh() ?? $render);
        }

        $queued = StoryFinalRender::query()
            ->where('status', StoryVideoJobStatus::Queued->value)
            ->where(static function ($query) use ($cutoff): void {
                $query->where('queued_at', '<=', $cutoff)->orWhere(static function ($query) use ($cutoff): void {
                    $query->whereNull('queued_at')->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->get();

        foreach ($queued as $render) {
            if (! $render instanceof StoryFinalRender || $this->jobExists($render)) {
                continue;
            }
            $redispatched++;
            if ($dry) {
                continue;
            }
            $pending = ProcessStoryRenderJob::dispatch($render->id, $render->uuid);
            $connection = config('story_render.queue.connection');
            if (is_string($connection) && $connection !== '') {
                $pending->onConnection($connection);
            }
            $pending->onQueue((string) config('story_render.queue.name', 'default'));
        }

        $this->info(($dry ? 'Would fail ' : 'Failed ').$failed.' interrupted render(s).');
        $this->info(($dry ? 'Would re-queue ' : 'Re-queued ').$redispatched.' render(s).');

        return self::SUCCESS;
    }

    private function jobExists(StoryFinalRender $render): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')->where('payload', 'like', '%'.$render->uuid.'%')->exists();
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
