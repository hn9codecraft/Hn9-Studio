<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StoryCharacterStatus;
use App\Story\Enums\StorySceneStatus;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;

/**
 * Builds the context a later scene must carry.
 * Reads persisted Story records only. Does not call a provider or invent missing text.
 */
final readonly class StoryContinuityService
{
    public function __construct(private StorySceneServiceInterface $scenes) {}

    /**
     * @return array<string, mixed>
     */
    public function packageForScene(Project $project, string $reelUuid, string $sceneUuid): array
    {
        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $scene->loadMissing('reel.workspace');
        $workspaceId = (int) $scene->reel->story_workspace_id;

        $bible = StoryBible::query()->where('story_workspace_id', $workspaceId)->first();
        $style = StoryStyleBible::query()->where('story_workspace_id', $workspaceId)->first();
        $characters = StoryCharacter::query()
            ->where('story_workspace_id', $workspaceId)
            ->where('status', '!=', StoryCharacterStatus::Archived->value)
            ->with('references')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $styleReferences = $style === null
            ? collect()
            : StoryStyleReference::query()
                ->where('story_style_bible_id', $style->id)
                ->orderBy('version')
                ->get();
        $previous = $this->previousScene($scene);

        $issues = [];
        if (! $bible instanceof StoryBible || ! $bible->isConfigured()) {
            $issues[] = 'story_bible_missing';
        }
        if ($characters->isEmpty()) {
            $issues[] = 'characters_missing';
        } elseif ($characters->every(static fn (StoryCharacter $character): bool => $character->references->isEmpty())) {
            $issues[] = 'character_references_missing';
        }
        if (! $style instanceof StoryStyleBible || ! $style->isConfigured()) {
            $issues[] = 'style_bible_missing';
        }
        if ($styleReferences->isEmpty()) {
            $issues[] = 'style_references_missing';
        }

        return [
            'scene_id' => $scene->uuid,
            'ready' => $issues === [],
            'issues' => $issues,
            'story_bible' => $bible instanceof StoryBible && $bible->isConfigured() ? [
                'id' => $bible->uuid,
                'concept' => $bible->concept,
                'genre' => $bible->genre,
                'audience' => $bible->audience,
                'language' => $bible->language,
                'tone' => $bible->tone,
                'world' => $bible->world,
                'location' => $bible->location,
                'time_period' => $bible->time_period,
                'narrative_style' => $bible->narrative_style,
                'video_style' => $bible->video_style,
                'aspect_ratio' => $bible->aspect_ratio,
            ] : null,
            'characters' => $characters->map(static fn (StoryCharacter $character): array => [
                'id' => $character->uuid,
                'name' => $character->name,
                'reference_ids' => $character->references->pluck('uuid')->values()->all(),
            ])->all(),
            'style_bible' => $style instanceof StoryStyleBible && $style->isConfigured() ? [
                'id' => $style->uuid,
                'visual_style' => $style->visual_style,
                'animation_style' => $style->animation_style,
                'lighting' => $style->lighting,
                'camera_style' => $style->camera_style,
                'color_direction' => $style->color_direction,
                'mood' => $style->mood,
                'aspect_ratio' => $style->aspect_ratio,
            ] : null,
            'style_reference_ids' => $styleReferences->pluck('uuid')->values()->all(),
            'previous_scene' => $previous instanceof StoryScene ? [
                'id' => $previous->uuid,
                'sequence' => $previous->sequence,
                'title' => $previous->title,
                'story' => $previous->story,
            ] : null,
        ];
    }

    private function previousScene(StoryScene $scene): ?StoryScene
    {
        return StoryScene::query()
            ->where('story_reel_id', $scene->story_reel_id)
            ->where('status', '!=', StorySceneStatus::Archived->value)
            ->where('sequence', '<', $scene->sequence)
            ->orderByDesc('sequence')
            ->orderByDesc('id')
            ->first();
    }
}
