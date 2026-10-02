<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessStoryVideoJob;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryVideoDispatchService;
use App\Story\Services\StoryVideoJobRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resumes in-flight Story video jobs after a worker crash. Accepted jobs are
 * polled on their stored operation id; claimed jobs without one are failed.
 * Nothing here submits a provider job.
 */
class RecoverStoryVideoJobs extends Command
{
    protected $signature = 'story:recover-video-jobs {--limit=100 : Maximum jobs to resume}';

    protected $description = 'Resume accepted Story video jobs without submitting new provider jobs';

    public function handle(StoryVideoJobRunner $runner): int
    {
        $jobs = StoryVideoGenerationJob::query()
            ->where('provider_key', StoryVideoDispatchService::LIVE_PROVIDER_KEY)
            ->whereIn('status', [
                StoryVideoJobStatus::Queued->value,
                StoryVideoJobStatus::Submitted->value,
                StoryVideoJobStatus::Processing->value,
            ])
            ->where(static function ($query): void {
                $query->whereNotNull('operation_id')->orWhereNotNull('started_at');
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($jobs as $job) {
            if ($runner->queued() && (string) $job->operation_id !== '') {
                ProcessStoryVideoJob::dispatch($job->id);

                continue;
            }

            try {
                $runner->advance($job);
            } catch (Throwable) {
                continue;
            }
        }

        $this->info('Resumed '.$jobs->count().' story video job(s).');

        return self::SUCCESS;
    }
}
