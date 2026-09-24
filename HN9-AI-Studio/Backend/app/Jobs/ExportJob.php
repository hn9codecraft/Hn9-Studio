<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Services\ExportServiceInterface;
use App\Enums\ExportStatus;
use App\Models\Export;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds the real ZIP for a queued export. Safe to retry: a completed package
 * is not rebuilt.
 */
class ExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $exportId) {}

    public function handle(ExportServiceInterface $exports): void
    {
        $export = Export::query()->find($this->exportId);

        if ($export === null || $export->statusEnum() === ExportStatus::Completed) {
            return;
        }

        $exports->build($export);
    }
}
