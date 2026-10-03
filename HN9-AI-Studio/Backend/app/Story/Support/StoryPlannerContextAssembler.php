<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Models\Project;
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;

/**
 * Assembles Project Story context for the planner without exposing private storage paths.
 */
final readonly class StoryPlannerContextAssembler
{
    public function __construct(
        private StoryBibleServiceInterface $bibles,
        private StoryCharacterServiceInterface $characters,
        private StoryStyleBibleServiceInterface $styles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(Project $project, StoryPlan $plan, ?StoryPlanVersion $previousVersion = null): array
    {
        $bible = $this->bibles->bibleForProject($project);
        $chars = $this->characters->listForProject($project);
        $style = $this->styles->styleForProject($project);

        $characterPayload = $chars->map(static function ($character): array {
            $approved = $character->approvedReference;

            return [
                'id' => $character->uuid,
                'name' => $character->name,
                'short_description' => $character->short_description,
                'age' => $character->age,
                'gender_presentation' => $character->gender_presentation,
                'appearance' => $character->appearance,
                'face_description' => $character->face_description,
                'hair' => $character->hair,
                'clothing' => $character->clothing,
                'personality' => $character->personality,
                'voice_description' => $character->voice_description,
                'special_details' => $character->special_details,
                'approved_reference' => $approved === null ? null : [
                    'id' => $approved->uuid,
                    'version' => $approved->version,
                    'status' => $approved->status,
                    'role' => $approved->role,
                    'source' => $approved->source,
                    'width' => $approved->width,
                    'height' => $approved->height,
                    'mime_type' => $approved->mime_type,
                ],
            ];
        })->values()->all();

        $approvedStyle = $style->approvedReference;

        return [
            'story_details' => [
                'id' => $bible->uuid,
                'configured' => $bible->isConfigured(),
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
                'default_duration' => $bible->default_duration,
            ],
            'characters' => $characterPayload,
            'visual_style' => [
                'id' => $style->uuid,
                'configured' => $style->isConfigured(),
                'visual_style' => $style->visual_style,
                'animation_style' => $style->animation_style,
                'lighting' => $style->lighting,
                'camera_style' => $style->camera_style,
                'color_direction' => $style->color_direction,
                'environment_style' => $style->environment_style,
                'mood' => $style->mood,
                'rendering_style' => $style->rendering_style,
                'visual_quality' => $style->visual_quality,
                'art_direction_notes' => $style->art_direction_notes,
                'aspect_ratio' => $style->aspect_ratio,
                'approved_reference' => $approvedStyle === null ? null : [
                    'id' => $approvedStyle->uuid,
                    'version' => $approvedStyle->version,
                    'status' => $approvedStyle->status,
                    'role' => $approvedStyle->role,
                    'source' => $approvedStyle->source,
                    'width' => $approvedStyle->width,
                    'height' => $approvedStyle->height,
                    'mime_type' => $approvedStyle->mime_type,
                ],
            ],
            'current_request' => [
                'plan_id' => $plan->uuid,
                'title' => $plan->title,
                'idea' => $plan->idea,
                'requested_duration_seconds' => $plan->requested_duration_seconds,
            ],
            'previous_plan' => $previousVersion === null || ! is_array($previousVersion->plan) ? null : [
                'version' => $previousVersion->version,
                'master_story' => $previousVersion->master_story,
                'title' => $previousVersion->plan['title'] ?? null,
                'logline' => $previousVersion->plan['logline'] ?? null,
                'scenes' => $previousVersion->plan['scenes'] ?? [],
            ],
        ];
    }
}
