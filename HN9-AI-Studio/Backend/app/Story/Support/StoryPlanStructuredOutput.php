<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Exceptions\StoryPlannerException;

/**
 * Validates and normalizes the canonical Story Planner structured JSON contract.
 * Malformed AI output is rejected — never silently repaired into a fake plan.
 */
final class StoryPlanStructuredOutput
{
    /**
     * @return array<string, mixed>
     */
    public function parseAndValidate(string $rawText, int $expectedTotalSeconds, array $expectedSegments): array
    {
        $decoded = $this->decodeJson($rawText);

        if (! is_array($decoded)) {
            throw StoryPlannerException::malformedOutput('Response is not a JSON object.');
        }

        $title = $this->requiredString($decoded, 'title');
        $logline = $this->requiredString($decoded, 'logline');
        $masterStory = $this->optionalString($decoded, 'master_story')
            ?? $this->optionalString($decoded, 'masterStory');

        $total = $decoded['total_duration_seconds'] ?? null;
        if (! is_int($total) && ! (is_string($total) && ctype_digit($total))) {
            throw StoryPlannerException::malformedOutput('total_duration_seconds is required.');
        }
        $total = (int) $total;
        if ($total !== $expectedTotalSeconds) {
            throw StoryPlannerException::malformedOutput(
                "total_duration_seconds must equal {$expectedTotalSeconds}.",
            );
        }

        $target = $decoded['scene_duration_target_seconds'] ?? StoryPlanDurationCalculator::SCENE_TARGET_SECONDS;
        $target = (int) $target;
        if ($target !== StoryPlanDurationCalculator::SCENE_TARGET_SECONDS) {
            throw StoryPlannerException::malformedOutput(
                'scene_duration_target_seconds must be '.StoryPlanDurationCalculator::SCENE_TARGET_SECONDS.'.',
            );
        }

        $scenes = $decoded['scenes'] ?? null;
        if (! is_array($scenes) || $scenes === []) {
            throw StoryPlannerException::malformedOutput('scenes must be a non-empty array.');
        }

        if (count($scenes) !== count($expectedSegments)) {
            throw StoryPlannerException::malformedOutput(
                'scenes count must match the calculated duration segmentation.',
            );
        }

        $normalizedScenes = [];
        foreach ($expectedSegments as $index => $expected) {
            $scene = $scenes[$index] ?? null;
            if (! is_array($scene)) {
                throw StoryPlannerException::malformedOutput("Scene index {$index} is invalid.");
            }

            $sequence = (int) ($scene['sequence'] ?? 0);
            $start = (int) ($scene['start_second'] ?? -1);
            $end = (int) ($scene['end_second'] ?? -1);
            $duration = (int) ($scene['duration_seconds'] ?? -1);

            if ($sequence !== $expected['sequence']
                || $start !== $expected['start_second']
                || $end !== $expected['end_second']
                || $duration !== $expected['duration_seconds']
            ) {
                throw StoryPlannerException::malformedOutput(
                    "Scene {$expected['sequence']} timing does not match the duration contract.",
                );
            }

            $continuity = $scene['continuity'] ?? [];
            if (! is_array($continuity)) {
                throw StoryPlannerException::malformedOutput("Scene {$sequence} continuity must be an object.");
            }

            $characters = $scene['characters'] ?? [];
            if (! is_array($characters)) {
                throw StoryPlannerException::malformedOutput("Scene {$sequence} characters must be an array.");
            }

            $dialogue = $scene['dialogue'] ?? [];
            if (! is_array($dialogue)) {
                throw StoryPlannerException::malformedOutput("Scene {$sequence} dialogue must be an array.");
            }

            $cleanDialogue = [];
            foreach ($dialogue as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $cleanDialogue[] = trim($item);
                } elseif (is_array($item)) {
                    $cleanDialogue[] = $item;
                }
            }

            $normalizedScenes[] = [
                'sequence' => $sequence,
                'start_second' => $start,
                'end_second' => $end,
                'duration_seconds' => $duration,
                'title' => $this->requiredString($scene, 'title'),
                'story' => $this->requiredString($scene, 'story'),
                'characters' => array_values(array_filter(array_map(
                    static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                    $characters,
                ))),
                'location' => $this->requiredString($scene, 'location'),
                'dialogue' => $cleanDialogue,
                'narration' => $this->optionalString($scene, 'narration') ?? '',
                'visual_prompt' => $this->requiredString($scene, 'visual_prompt'),
                'motion_prompt' => $this->requiredString($scene, 'motion_prompt'),
                'audio_direction' => $this->requiredString($scene, 'audio_direction'),
                'continuity' => [
                    'previous_scene' => $this->optionalString($continuity, 'previous_scene') ?? '',
                    'next_scene' => $this->optionalString($continuity, 'next_scene') ?? '',
                    'character_state' => $this->optionalString($continuity, 'character_state') ?? '',
                    'environment_state' => $this->optionalString($continuity, 'environment_state') ?? '',
                ],
            ];
        }

        $remainder = is_string($decoded['remainder_strategy'] ?? null)
            ? $decoded['remainder_strategy']
            : null;

        return [
            'title' => $title,
            'logline' => $logline,
            'master_story' => $masterStory ?? $logline,
            'total_duration_seconds' => $total,
            'scene_duration_target_seconds' => $target,
            'remainder_strategy' => $remainder,
            'scenes' => $normalizedScenes,
        ];
    }

    private function decodeJson(string $rawText): mixed
    {
        $trimmed = trim($rawText);
        if ($trimmed === '') {
            throw StoryPlannerException::malformedOutput('Empty provider response.');
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $trimmed, $matches) === 1) {
            $trimmed = $matches[1];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw StoryPlannerException::malformedOutput('JSON decode failed: '.json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw StoryPlannerException::malformedOutput("Missing required field '{$key}'.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw StoryPlannerException::malformedOutput("Field '{$key}' must be a string.");
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
