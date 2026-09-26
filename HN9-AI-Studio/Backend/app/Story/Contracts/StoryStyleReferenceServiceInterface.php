<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryStyleReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface StoryStyleReferenceServiceInterface
{
    /**
     * @return Collection<int, StoryStyleReference>
     */
    public function listForProject(Project $project): Collection;

    public function getForProject(Project $project, string $referenceUuid): StoryStyleReference;

    public function upload(Project $project, UploadedFile $file, ?string $role = null): StoryStyleReference;

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(Project $project, array $options = []): StoryStyleReference;

    public function submitReview(Project $project, string $referenceUuid, User $actor, ?string $comment = null): StoryStyleReference;

    public function approve(Project $project, string $referenceUuid, User $actor, ?string $comment = null): StoryStyleReference;

    public function reject(Project $project, string $referenceUuid, User $actor, string $comment): StoryStyleReference;

    public function archive(Project $project, string $referenceUuid, User $actor): StoryStyleReference;

    public function fileBytes(StoryStyleReference $reference): string;
}
