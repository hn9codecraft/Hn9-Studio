<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExportException;
use App\Models\Export;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\Script;
use App\Models\Video;
use App\Support\StorageHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds a real ZIP on disk from approved studio files. Binaries are added
 * from storage paths so large videos are not loaded into PHP memory.
 */
class ExportPackageBuilder
{
    public function __construct(private FinalAssetSelector $selector) {}

    /**
     * @param  array{
     *     scripts: Collection<int, Script>,
     *     images: Collection<int, Image>,
     *     videos: Collection<int, Video>
     * }  $assets
     * @return array{path: string, filename: string, size: int, manifest: array<string, mixed>}
     */
    public function build(Project $project, Export $export, array $assets): array
    {
        $root = $this->safeFolder($project->name ?: 'project');
        $filename = $root.'-export.zip';
        $relative = $export->uuid.'/'.$filename;
        $physicalDisk = StorageHelper::disk('exports');
        $absoluteZip = Storage::disk($physicalDisk)->path($relative);

        Storage::disk($physicalDisk)->makeDirectory($export->uuid);

        $zip = new ZipArchive;
        $opened = $zip->open($absoluteZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw ExportException::packageFailed();
        }

        try {
            $manifest = $this->manifest($project, $export, $assets);
            $readme = $this->readme($project, $manifest);

            $this->addString($zip, $root.'/README.txt', $readme);
            $this->addString($zip, $root.'/metadata/project.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            foreach ($assets['scripts']->values() as $index => $script) {
                $name = $assets['scripts']->count() === 1
                    ? 'final-script.txt'
                    : sprintf('final-script-%02d.txt', $index + 1);
                $this->addString($zip, $root.'/script/'.$name, $this->scriptText($script));
            }

            foreach ($assets['images']->values() as $index => $image) {
                $this->addMedia($zip, $image->file, $root.'/images/'.sprintf('image-%02d.', $index + 1));
            }

            foreach ($assets['videos']->values() as $index => $video) {
                $this->addMedia($zip, $video->file, $root.'/videos/'.sprintf('video-%02d.', $index + 1));
            }

            if (! $zip->close()) {
                throw ExportException::packageFailed();
            }
        } catch (\Throwable $exception) {
            $zip->close();
            if (is_file($absoluteZip)) {
                @unlink($absoluteZip);
            }

            throw $exception instanceof ExportException
                ? $exception
                : ExportException::packageFailed($exception);
        }

        if (! is_file($absoluteZip) || filesize($absoluteZip) <= 0) {
            throw ExportException::packageFailed();
        }

        return [
            'path' => $relative,
            'filename' => $filename,
            'size' => (int) filesize($absoluteZip),
            'manifest' => $manifest,
        ];
    }

    /**
     * @param  array{
     *     scripts: Collection<int, Script>,
     *     images: Collection<int, Image>,
     *     videos: Collection<int, Video>
     * }  $assets
     * @return array<string, mixed>
     */
    private function manifest(Project $project, Export $export, array $assets): array
    {
        return [
            'project' => [
                'id' => $project->uuid,
                'name' => $project->name,
                'type' => $project->type,
                'status' => $project->status,
                'exported_at' => now()->toIso8601String(),
            ],
            'export' => [
                'id' => $export->uuid,
            ],
            'assets' => [
                'scripts' => $assets['scripts']->map(fn (Script $script): array => [
                    'id' => $script->uuid,
                    'title' => $script->title,
                    'parent_id' => $script->parentScript?->uuid,
                    'created_at' => $script->created_at?->toIso8601String(),
                ])->values()->all(),
                'images' => $assets['images']->map(fn (Image $image): array => [
                    'id' => $image->uuid,
                    'title' => $image->title,
                    'mime_type' => $image->file?->mime_type,
                    'parent_id' => $image->parentImage?->uuid ?? null,
                    'created_at' => $image->created_at?->toIso8601String(),
                ])->values()->all(),
                'videos' => $assets['videos']->map(fn (Video $video): array => [
                    'id' => $video->uuid,
                    'title' => $video->title,
                    'mime_type' => $video->file?->mime_type,
                    'duration' => $video->duration,
                    'parent_id' => $video->parentVideo?->uuid ?? null,
                    'created_at' => $video->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'counts' => [
                'scripts' => $assets['scripts']->count(),
                'images' => $assets['images']->count(),
                'videos' => $assets['videos']->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function readme(Project $project, array $manifest): string
    {
        $lines = [
            'HN9 AI Studio project export',
            '',
            'Project: '.$project->name,
            'Project ID: '.$project->uuid,
            'Exported: '.(string) ($manifest['project']['exported_at'] ?? ''),
            '',
            'Scripts: '.(string) ($manifest['counts']['scripts'] ?? 0),
            'Images: '.(string) ($manifest['counts']['images'] ?? 0),
            'Videos: '.(string) ($manifest['counts']['videos'] ?? 0),
            '',
            'Only current approved assets are included. Older versions are omitted.',
        ];

        return implode("\n", $lines)."\n";
    }

    private function scriptText(Script $script): string
    {
        return trim((string) $script->title)."\n\n".trim((string) $script->body)."\n";
    }

    private function addString(ZipArchive $zip, string $name, string $contents): void
    {
        if ($zip->addFromString($name, $contents) === false) {
            throw ExportException::packageFailed();
        }
    }

    private function addMedia(ZipArchive $zip, ?MediaFile $file, string $prefix): void
    {
        if ($file === null || ! $this->selector->mediaExists($file)) {
            throw ExportException::assetFileMissing('asset', 'unknown');
        }

        $disk = StorageHelper::disk($file->disk);
        $absolute = Storage::disk($disk)->path($file->path);
        $extension = $file->extension ?: pathinfo($file->path, PATHINFO_EXTENSION) ?: 'bin';
        $name = $prefix.$extension;

        if ($zip->addFile($absolute, $name) === false) {
            throw ExportException::packageFailed();
        }
    }

    private function safeFolder(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'project';
    }
}
