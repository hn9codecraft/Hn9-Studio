<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\Image;
use App\Models\Project;
use App\Models\User;

interface ImageGenerationServiceInterface
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{image: Image, dispatch: array<string, mixed>}
     */
    public function generate(Project $project, array $input, ?User $causer = null, ?Image $parent = null): array;
}
