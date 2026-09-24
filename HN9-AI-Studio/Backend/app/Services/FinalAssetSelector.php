<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageStatus;
use App\Enums\ProjectStatus;
use App\Enums\ScriptStatus;
use App\Enums\VideoStatus;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\Script;
use App\Models\Video;
use App\Support\StorageHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic current-approved asset selection for a project.
 *
 * Current means: status is approved, and no later approved child exists in
 * the parent_* lineage. created_at is never used as the authority.
 */
final class FinalAssetSelector
{
    /**
     * @return array{
     *     ready: bool,
     *     finalized: bool,
     *     can_finalize: bool,
     *     can_export: bool,
     *     issues: list<array{code: string, message: string}>,
     *     scripts: Collection<int, Script>,
     *     images: Collection<int, Image>,
     *     videos: Collection<int, Video>
     * }
     */
    public function inspect(Project $project): array
    {
        $scripts = $this->currentApprovedScripts($project);
        $images = $this->currentApprovedImages($project);
        $videos = $this->currentApprovedVideos($project);
        $issues = [];

        $status = ProjectStatus::tryFrom((string) $project->status);
        $archived = $status === ProjectStatus::Archived;
        $finalized = $status === ProjectStatus::Completed;

        if ($archived) {
            $issues[] = [
                'code' => 'project_archived',
                'message' => 'This project is archived.',
            ];
        }

        if ($scripts->isEmpty()) {
            $issues[] = [
                'code' => 'script_not_approved',
                'message' => 'An approved script is required.',
            ];
        } else {
            foreach ($scripts as $script) {
                if (! is_string($script->body) || trim($script->body) === '') {
                    $issues[] = [
                        'code' => 'script_empty',
                        'message' => 'The approved script has no content.',
                    ];
                }
            }
        }

        if ($images->isEmpty()) {
            $issues[] = [
                'code' => 'image_not_approved',
                'message' => 'An approved image is required.',
            ];
        } else {
            foreach ($images as $image) {
                if (! $this->mediaExists($image->file)) {
                    $issues[] = [
                        'code' => 'image_file_missing',
                        'message' => 'An approved image is missing its stored file.',
                    ];
                } elseif (! $this->validMime($image->file, 'image/')) {
                    $issues[] = [
                        'code' => 'image_invalid_mime',
                        'message' => 'An approved image has an invalid file type.',
                    ];
                }
            }
        }

        if ($videos->isEmpty()) {
            $issues[] = [
                'code' => 'video_not_approved',
                'message' => 'An approved video is required.',
            ];
        } else {
            foreach ($videos as $video) {
                if (! $this->mediaExists($video->file)) {
                    $issues[] = [
                        'code' => 'video_file_missing',
                        'message' => 'An approved video is missing its stored file.',
                    ];
                } elseif (! $this->validMime($video->file, 'video/')) {
                    $issues[] = [
                        'code' => 'video_invalid_mime',
                        'message' => 'An approved video has an invalid file type.',
                    ];
                }
            }
        }

        if ($this->hasInFlightVideo($project)) {
            $issues[] = [
                'code' => 'video_processing',
                'message' => 'A video is still processing.',
            ];
        }

        $ready = $issues === [];
        $canFinalize = $ready && ! $finalized && $status !== null && $status->isEditable();
        $canExport = $ready && $finalized;

        return [
            'ready' => $ready,
            'finalized' => $finalized,
            'can_finalize' => $canFinalize,
            'can_export' => $canExport,
            'issues' => $issues,
            'scripts' => $scripts,
            'images' => $images,
            'videos' => $videos,
        ];
    }

    /**
     * @return Collection<int, Script>
     */
    public function currentApprovedScripts(Project $project): Collection
    {
        $approved = Script::query()
            ->with('parentScript')
            ->where('project_id', $project->getKey())
            ->where('status', ScriptStatus::Approved->value)
            ->get();

        return $this->leaves($approved, 'parent_script_id');
    }

    /**
     * @return Collection<int, Image>
     */
    public function currentApprovedImages(Project $project): Collection
    {
        $approved = Image::query()
            ->with(['file', 'parentImage'])
            ->where('project_id', $project->getKey())
            ->where('status', ImageStatus::Approved->value)
            ->get();

        return $this->leaves($approved, 'parent_image_id');
    }

    /**
     * @return Collection<int, Video>
     */
    public function currentApprovedVideos(Project $project): Collection
    {
        $approved = Video::query()
            ->with(['file', 'parentVideo'])
            ->where('project_id', $project->getKey())
            ->where('status', VideoStatus::Approved->value)
            ->get();

        return $this->leaves($approved, 'parent_video_id');
    }

    public function fingerprint(Project $project): string
    {
        $inspection = $this->inspect($project);

        $ids = [
            ...$inspection['scripts']->pluck('uuid')->all(),
            ...$inspection['images']->pluck('uuid')->all(),
            ...$inspection['videos']->pluck('uuid')->all(),
        ];
        sort($ids);

        return hash('sha256', implode('|', $ids));
    }

    public function mediaExists(?MediaFile $file): bool
    {
        if ($file === null || $file->path === '') {
            return false;
        }

        $disk = StorageHelper::disk($file->disk);

        return Storage::disk($disk)->exists($file->path);
    }

    private function validMime(MediaFile $file, string $prefix): bool
    {
        return is_string($file->mime_type) && str_starts_with($file->mime_type, $prefix);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     * @param  Collection<int, T>  $approved
     * @return Collection<int, T>
     */
    private function leaves(Collection $approved, string $parentKey): Collection
    {
        $parentIds = $approved
            ->pluck($parentKey)
            ->filter(static fn (mixed $id): bool => $id !== null)
            ->unique()
            ->all();

        return $approved
            ->filter(static fn ($item): bool => ! in_array($item->getKey(), $parentIds, true))
            ->values();
    }

    private function hasInFlightVideo(Project $project): bool
    {
        return Video::query()
            ->where('project_id', $project->getKey())
            ->whereIn('status', [VideoStatus::Pending->value, VideoStatus::Processing->value])
            ->exists();
    }
}
