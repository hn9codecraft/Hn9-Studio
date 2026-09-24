<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\Video;

interface VideoGenerationServiceInterface
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{video: Video, dispatch: array<string, mixed>}
     */
    public function generate(Project $project, array $input, ?User $causer = null, ?Video $parent = null): array;

    public function refresh(Video $video): Video;

    public function storedFile(Video $video): ?\App\Models\MediaFile;
}
