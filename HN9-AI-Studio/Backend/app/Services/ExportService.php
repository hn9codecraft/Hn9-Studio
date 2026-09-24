<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ExportServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Enums\ExportStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ExportException;
use App\Jobs\ExportJob;
use App\Models\Export;
use App\Models\Project;
use App\Models\User;
use App\Support\StorageHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Completes the existing export stub. Packages only current approved assets
 * after the project has been finalized to completed.
 */
final readonly class ExportService implements ExportServiceInterface
{
    public function __construct(
        private FinalAssetSelector $selector,
        private ExportPackageBuilder $builder,
        private ProjectServiceInterface $projects,
        private ActivityLoggerInterface $activity,
    ) {}

    public function readiness(Project $project): array
    {
        $inspection = $this->selector->inspect($project);
        $latest = $this->latestExport($project);

        return [
            'ready' => $inspection['ready'],
            'finalized' => $inspection['finalized'],
            'can_finalize' => $inspection['can_finalize'],
            'can_export' => $inspection['can_export'],
            'workflow_state' => $this->workflowState($inspection, $latest),
            'issues' => $inspection['issues'],
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'status' => $project->status,
            ],
            'scripts' => $inspection['scripts']->map(fn ($script): array => [
                'id' => $script->uuid,
                'title' => $script->title,
                'status' => $script->status,
            ])->values()->all(),
            'images' => $inspection['images']->map(fn ($image): array => [
                'id' => $image->uuid,
                'title' => $image->title,
                'status' => $image->status,
                'has_file' => $image->file !== null,
            ])->values()->all(),
            'videos' => $inspection['videos']->map(fn ($video): array => [
                'id' => $video->uuid,
                'title' => $video->title,
                'status' => $video->status,
                'has_file' => $video->file !== null,
            ])->values()->all(),
            'latest_export' => $latest === null ? null : $this->publicExport($latest),
        ];
    }

    public function finalize(Project $project, User $actor): Project
    {
        $this->assertNotArchived($project);

        $inspection = $this->assertReady($project);

        if ($inspection['finalized']) {
            return $project;
        }

        $status = ProjectStatus::tryFrom((string) $project->status);

        if ($status === ProjectStatus::Draft) {
            $project = $this->projects->changeStatus($project, ProjectStatus::Active, $actor);
        }

        $updated = $this->projects->changeStatus($project, ProjectStatus::Completed, $actor);
        $this->activity->log('project.finalized', $updated, $actor, 'Project finalized for export');

        return $updated;
    }

    public function create(User $user, array $payload): Export
    {
        $projectUuid = $this->nullableString($payload['project_id'] ?? $payload['project'] ?? null);

        if ($projectUuid === null) {
            throw ExportException::invalidRequest();
        }

        $project = $this->projects->getByUuid($projectUuid);

        if ($project->user_id !== $user->getKey() && ! $user->isAdmin()) {
            abort(403);
        }

        return $this->createForProject($project, $user);
    }

    public function createForProject(Project $project, User $actor): Export
    {
        $this->assertNotArchived($project);

        $inspection = $this->assertReady($project);

        if (! $inspection['finalized']) {
            throw ExportException::notFinalized((string) $project->status);
        }

        $fingerprint = $this->selector->fingerprint($project);

        $active = Export::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Processing->value])
            ->latest('id')
            ->first();

        if ($active !== null) {
            throw ExportException::inProgress($active->uuid);
        }

        $existing = Export::query()
            ->where('project_id', $project->getKey())
            ->where('fingerprint', $fingerprint)
            ->where('status', ExportStatus::Completed->value)
            ->latest('id')
            ->first();

        if ($existing !== null && is_string($existing->path) && Storage::disk(StorageHelper::disk($existing->disk))->exists($existing->path)) {
            return $existing->load('project');
        }

        $export = Export::query()->create([
            'user_id' => $actor->getKey(),
            'project_id' => $project->getKey(),
            'status' => ExportStatus::Queued->value,
            'disk' => 'exports',
            'fingerprint' => $fingerprint,
        ]);

        $this->activity->log('export.started', $export, $actor, 'Project export started');

        ExportJob::dispatch($export->getKey());

        return ($export->fresh() ?? $export)->load('project');
    }

    public function index(User $user): Collection
    {
        return Export::query()
            ->with('project')
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function listForProject(Project $project): Collection
    {
        return Export::query()
            ->with('project')
            ->where('project_id', $project->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function show(User $user, string $uuid): ?Export
    {
        $export = Export::query()->with('project')->where('uuid', $uuid)->first();

        if ($export === null) {
            return null;
        }

        if ($export->user_id !== $user->getKey() && $export->project?->user_id !== $user->getKey() && ! $user->isAdmin()) {
            abort(403);
        }

        return $export;
    }

    public function findForProject(Project $project, string $uuid): Export
    {
        $export = Export::query()->with('project')->where('uuid', $uuid)->firstOrFail();

        abort_unless($export->project_id === $project->getKey(), 404);

        return $export;
    }

    public function download(User $user, string $uuid): StreamedResponse
    {
        $export = $this->show($user, $uuid);

        if ($export === null) {
            abort(404);
        }

        return $this->stream($export);
    }

    public function downloadForProject(Project $project, string $uuid): StreamedResponse
    {
        return $this->stream($this->findForProject($project, $uuid));
    }

    public function build(Export $export): Export
    {
        $export->refresh();

        if ($export->statusEnum() === ExportStatus::Completed && is_string($export->path)) {
            return $export;
        }

        $project = $export->project ?? $export->project()->first();

        if ($project === null) {
            return $this->fail($export, 'The export project is missing.');
        }

        $export->update(['status' => ExportStatus::Processing->value]);

        try {
            $inspection = $this->assertReady($project);
            $package = $this->builder->build($project, $export, [
                'scripts' => $inspection['scripts'],
                'images' => $inspection['images'],
                'videos' => $inspection['videos'],
            ]);
        } catch (ExportException $exception) {
            return $this->fail($export, $exception->getMessage());
        } catch (\Throwable) {
            return $this->fail($export, 'The export package could not be created.');
        }

        $export->update([
            'status' => ExportStatus::Completed->value,
            'path' => $package['path'],
            'filename' => $package['filename'],
            'size' => $package['size'],
            'manifest' => $package['manifest'],
            'error' => null,
            'completed_at' => now(),
        ]);

        $this->activity->log('export.completed', $export, null, 'Project export package stored');

        return $export->fresh() ?? $export;
    }

    private function assertNotArchived(Project $project): void
    {
        if (ProjectStatus::tryFrom((string) $project->status) === ProjectStatus::Archived) {
            throw ExportException::projectArchived();
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function workflowState(array $inspection, ?Export $latest): string
    {
        if ($inspection['issues'] !== []) {
            return 'not_ready';
        }

        if ($inspection['can_finalize']) {
            return 'ready_to_finalize';
        }

        if ($latest !== null && $latest->statusEnum()->isInFlight()) {
            return 'exporting';
        }

        if ($latest !== null && $latest->statusEnum() === ExportStatus::Failed) {
            return 'export_failed';
        }

        if ($latest !== null && $latest->statusEnum() === ExportStatus::Completed) {
            return 'export_ready';
        }

        if ($inspection['can_export']) {
            return 'ready_to_export';
        }

        return 'not_ready';
    }

    /**
     * @return array<string, mixed>
     */
    private function assertReady(Project $project): array
    {
        $inspection = $this->selector->inspect($project);

        if ($inspection['issues'] !== []) {
            throw ExportException::notReady($inspection['issues']);
        }

        return $inspection;
    }

    private function latestExport(Project $project): ?Export
    {
        return Export::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicExport(Export $export): array
    {
        return [
            'id' => $export->uuid,
            'status' => $export->status,
            'filename' => $export->filename,
            'size' => $export->size,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'created_at' => $export->created_at?->toIso8601String(),
        ];
    }

    private function stream(Export $export): StreamedResponse
    {
        if (! $export->statusEnum()->isDownloadable()) {
            throw ExportException::notReadyToDownload();
        }

        if (! is_string($export->path) || $export->path === '') {
            throw ExportException::missingPackage();
        }

        $disk = StorageHelper::disk($export->disk);

        if (! Storage::disk($disk)->exists($export->path)) {
            throw ExportException::missingPackage();
        }

        $filename = $export->filename ?: 'project-export.zip';

        return Storage::disk($disk)->download($export->path, $filename, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function fail(Export $export, string $message): Export
    {
        $export->update([
            'status' => ExportStatus::Failed->value,
            'error' => $message,
        ]);

        $this->activity->log('export.failed', $export, null, 'Project export failed');

        return $export->fresh() ?? $export;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
