<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryCharacterReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface StoryCharacterReferenceServiceInterface
{
    /**
     * @return Collection<int, StoryCharacterReference>
     */
    public function listForCharacter(Project $project, string $characterUuid): Collection;

    public function getForCharacter(Project $project, string $characterUuid, string $referenceUuid): StoryCharacterReference;

    public function upload(Project $project, string $characterUuid, UploadedFile $file, ?string $role = null): StoryCharacterReference;

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(Project $project, string $characterUuid, array $options = []): StoryCharacterReference;

    public function submitReview(Project $project, string $characterUuid, string $referenceUuid, User $actor, ?string $comment = null): StoryCharacterReference;

    public function approve(Project $project, string $characterUuid, string $referenceUuid, User $actor, ?string $comment = null): StoryCharacterReference;

    public function reject(Project $project, string $characterUuid, string $referenceUuid, User $actor, string $comment): StoryCharacterReference;

    public function archive(Project $project, string $characterUuid, string $referenceUuid, User $actor): StoryCharacterReference;

    public function fileBytes(StoryCharacterReference $reference): string;
}
