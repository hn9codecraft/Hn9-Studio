<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Enums\ExportStatus;
use App\Exceptions\ExportException;
use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryExport;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StorySceneVersion;
use App\Support\StorageHelper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Packages an approved Story final render with its scene scripts and media into a private ZIP.
 * Built from stored files only. No provider calls.
 */
final class StoryExportService
{
    private const MEDIA_DISKS = ['videos', 'voice'];

    /**
     * @return array{export: StoryExport, created: bool}
     */
    public function create(Project $project, string $reelUuid, string $renderUuid, User $actor): array
    {
        $reel = $this->reel($project, $reelUuid);
        $render = StoryFinalRender::query()
            ->where('uuid', $renderUuid)
            ->where('story_reel_id', $reel->id)
            ->first();
        if (! $render instanceof StoryFinalRender) {
            throw StoryException::notFound('Render');
        }
        $this->assertApproved($render);

        $existing = StoryExport::query()
            ->where('story_final_render_id', $render->id)
            ->where('status', ExportStatus::Completed->value)
            ->latest('id')
            ->first();
        if ($existing instanceof StoryExport
            && is_string($existing->path)
            && Storage::disk(StorageHelper::disk($existing->disk))->exists($existing->path)) {
            return ['export' => $existing, 'created' => false];
        }

        $export = StoryExport::query()->create([
            'user_id' => $actor->getKey(),
            'project_id' => $project->getKey(),
            'story_reel_id' => $reel->id,
            'story_final_render_id' => $render->id,
            'status' => ExportStatus::Queued->value,
            'disk' => 'exports',
        ]);

        return ['export' => $this->build($project, $reel, $render, $export), 'created' => true];
    }

    public function find(Project $project, string $reelUuid, string $exportUuid): StoryExport
    {
        $reel = $this->reel($project, $reelUuid);
        $export = StoryExport::query()
            ->with(['project', 'render'])
            ->where('uuid', $exportUuid)
            ->where('project_id', $project->getKey())
            ->where('story_reel_id', $reel->id)
            ->first();
        if (! $export instanceof StoryExport) {
            throw StoryException::notFound('Export');
        }

        return $export;
    }

    public function download(StoryExport $export): StreamedResponse
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

        return Storage::disk($disk)->download($export->path, $export->filename ?: 'story-export.zip', [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function build(Project $project, StoryReel $reel, StoryFinalRender $render, StoryExport $export): StoryExport
    {
        $export->forceFill(['status' => ExportStatus::Processing->value])->save();

        $root = Str::slug((string) ($project->name ?: 'story')) ?: 'story';
        $filename = $root.'-story-export.zip';
        $relative = 'story/'.$export->uuid.'/'.$filename;
        $disk = StorageHelper::disk('exports');
        Storage::disk($disk)->makeDirectory('story/'.$export->uuid);
        $absolute = Storage::disk($disk)->path($relative);

        try {
            $manifest = $this->package($absolute, $root, $project, $reel, $render, $export);
            if (! is_file($absolute) || filesize($absolute) <= 0) {
                throw ExportException::packageFailed();
            }
        } catch (Throwable $exception) {
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            $export->forceFill([
                'status' => ExportStatus::Failed->value,
                'path' => null,
                'filename' => null,
                'size' => null,
                'error' => $exception instanceof ExportException || $exception instanceof StoryException
                    ? $exception->getMessage()
                    : 'The export package could not be created.',
            ])->save();

            throw $exception instanceof ExportException || $exception instanceof StoryException
                ? $exception
                : ExportException::packageFailed($exception);
        }

        $export->forceFill([
            'status' => ExportStatus::Completed->value,
            'path' => $relative,
            'filename' => $filename,
            'size' => (int) filesize($absolute),
            'manifest' => $manifest,
            'error' => null,
            'completed_at' => now(),
        ])->save();

        return $export->fresh(['project', 'render']) ?? $export;
    }

    /**
     * @return array<string, mixed>
     */
    private function package(
        string $absolute,
        string $root,
        Project $project,
        StoryReel $reel,
        StoryFinalRender $render,
        StoryExport $export,
    ): array {
        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ExportException::packageFailed();
        }

        try {
            $this->addFile($zip, (string) $render->disk, (string) $render->path, $root.'/final/final-render.mp4');

            $scenes = [];
            $media = [];
            $audioIndex = 0;
            $seen = [];
            $snapshot = is_array($render->timeline_snapshot) ? $render->timeline_snapshot : [];
            foreach ($snapshot['clips'] ?? [] as $clip) {
                if (! is_array($clip)) {
                    continue;
                }
                $disk = (string) ($clip['disk'] ?? '');
                $path = (string) ($clip['path'] ?? '');
                $versionUuid = is_string($clip['source_version_id'] ?? null) ? $clip['source_version_id'] : null;
                $version = $versionUuid !== null
                    ? StorySceneVersion::query()->with('scene')->where('uuid', $versionUuid)->first()
                    : null;

                if ($version instanceof StorySceneVersion && $version->scene?->story_reel_id === $reel->id) {
                    $folder = sprintf('%s/scenes/scene-%02d', $root, (int) $version->scene->sequence);
                    if (! isset($scenes[$version->uuid])) {
                        $this->addString($zip, $folder.'/script.txt', $this->scriptText($version));
                        $scenes[$version->uuid] = [
                            'scene_id' => $version->scene->uuid,
                            'sequence' => (int) $version->scene->sequence,
                            'version_id' => $version->uuid,
                            'version' => (int) $version->version,
                            'review_status' => $version->status,
                            'title' => $version->title ?: $version->scene->title,
                            'script' => $this->packagePath($root, $folder.'/script.txt'),
                            'media' => [],
                        ];
                    }
                    $entry = $folder.'/video-v'.(int) $version->version.'.'.$this->extension($path, 'mp4');
                } else {
                    $audioIndex++;
                    $entry = sprintf('%s/audio/audio-%02d.%s', $root, $audioIndex, $this->extension($path, 'mp3'));
                }

                $key = $disk.'|'.$path;
                if (! isset($seen[$key])) {
                    $this->addFile($zip, $disk, $path, $entry);
                    $seen[$key] = $this->packagePath($root, $entry);
                    $media[] = [
                        'kind' => (string) ($clip['media_kind'] ?? 'video'),
                        'file' => $seen[$key],
                    ];
                }
                if ($version instanceof StorySceneVersion && isset($scenes[$version->uuid])
                    && ! in_array($seen[$key], $scenes[$version->uuid]['media'], true)) {
                    $scenes[$version->uuid]['media'][] = $seen[$key];
                }
            }

            $manifest = [
                'project' => [
                    'id' => $project->uuid,
                    'name' => $project->name,
                ],
                'reel' => [
                    'id' => $reel->uuid,
                    'title' => $reel->title ?? null,
                ],
                'export' => [
                    'id' => $export->uuid,
                    'exported_at' => now()->toIso8601String(),
                ],
                'final_render' => [
                    'id' => $render->uuid,
                    'timeline_version' => $render->timeline_version,
                    'review_status' => $render->review_status,
                    'size_bytes' => $render->size_bytes,
                    'checksum_sha256' => $render->checksum,
                    'file' => 'final/final-render.mp4',
                ],
                'scenes' => array_values($scenes),
                'media' => $media,
                'counts' => [
                    'scenes' => count($scenes),
                    'media' => count($media),
                ],
            ];

            $this->addString($zip, $root.'/metadata/story.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->addString($zip, $root.'/README.txt', $this->readme($project, $manifest));

            if (! $zip->close()) {
                throw ExportException::packageFailed();
            }
        } catch (Throwable $exception) {
            @$zip->close();

            throw $exception;
        }

        return $manifest;
    }

    private function assertApproved(StoryFinalRender $render): void
    {
        if ($render->status !== StoryVideoJobStatus::Completed->value
            || $render->review_status !== StoryReviewStatus::Approved->value) {
            throw new StoryException(
                'Approve the final render before exporting.',
                'story_export_render_not_approved',
                422,
            );
        }
        if (! is_string($render->disk) || ! is_string($render->path)
            || ! Storage::disk($render->disk)->exists($render->path)) {
            throw new StoryException('The final render file is missing.', 'story_export_render_missing', 422);
        }
    }

    private function addFile(ZipArchive $zip, string $disk, string $path, string $entry): void
    {
        if (! in_array($disk, self::MEDIA_DISKS, true) || $path === '' || str_contains($path, '..')
            || ! Storage::disk($disk)->exists($path)) {
            throw new StoryException('A packaged media file is missing.', 'story_export_media_missing', 422);
        }
        if ($zip->addFile(Storage::disk($disk)->path($path), $entry) === false) {
            throw ExportException::packageFailed();
        }
    }

    private function addString(ZipArchive $zip, string $entry, string $contents): void
    {
        if ($zip->addFromString($entry, $contents) === false) {
            throw ExportException::packageFailed();
        }
    }

    private function scriptText(StorySceneVersion $version): string
    {
        $scene = $version->scene;
        $parts = [trim((string) ($version->title ?: $scene?->title))];
        foreach ([
            'Story' => $version->story ?: $scene?->story,
            'Dialogue' => $scene?->dialogue,
            'Narration' => $scene?->narration,
        ] as $label => $value) {
            $text = is_array($value) ? $this->lines($value) : trim((string) $value);
            if ($text !== '') {
                $parts[] = $label.":\n".$text;
            }
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''))."\n";
    }

    /**
     * @param  array<mixed>  $value
     */
    private function lines(array $value): string
    {
        $lines = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = implode(': ', array_filter(array_map(
                    static fn ($part): string => is_scalar($part) ? trim((string) $part) : '',
                    $item,
                ), static fn (string $part): bool => $part !== ''));
            }
            if (is_scalar($item) && trim((string) $item) !== '') {
                $lines[] = trim((string) $item);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function readme(Project $project, array $manifest): string
    {
        return implode("\n", [
            'HN9 AI Studio story export',
            '',
            'Project: '.$project->name,
            'Final render: final/final-render.mp4',
            'Scenes: '.(string) ($manifest['counts']['scenes'] ?? 0),
            'Media files: '.(string) ($manifest['counts']['media'] ?? 0),
            '',
            'Metadata: metadata/story.json',
        ])."\n";
    }

    private function packagePath(string $root, string $entry): string
    {
        return substr($entry, strlen($root) + 1);
    }

    private function extension(string $path, string $fallback): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : $fallback;
    }

    private function reel(Project $project, string $reelUuid): StoryReel
    {
        $reel = StoryReel::query()
            ->where('uuid', $reelUuid)
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
        if (! $reel instanceof StoryReel) {
            throw StoryException::notFound('Reel');
        }

        return $reel;
    }
}
