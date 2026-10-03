<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * Builds provider-agnostic text prompts for the Story Planner.
 */
final class StoryPlannerPromptBuilder
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $durationPlan
     * @return array{system: string, prompt: string}
     */
    public function build(array $context, array $durationPlan, ?string $regenerationInstruction = null): array
    {
        $system = implode("\n", [
            'You are the HN9 Project Story Planner.',
            'Produce ONLY valid JSON matching the output contract.',
            'Preserve the Story Details, Character Profiles, and Visual Style definitions.',
            'Do not invent conflicting character or style details.',
            'Do not change approved character/style definitions unless the user request explicitly asks.',
            'Break the story into the exact timed scenes provided.',
            'Never output markdown outside a single JSON object.',
        ]);

        $segmentsJson = json_encode($durationPlan['segments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
PROJECT STORY DETAILS / CHARACTERS / VISUAL STYLE / PREVIOUS CONTEXT (JSON):
{$contextJson}

DURATION CONTRACT:
total_duration_seconds={$durationPlan['total_duration_seconds']}
scene_duration_target_seconds={$durationPlan['scene_duration_target_seconds']}
remainder_strategy={$durationPlan['remainder_strategy']}
scene_count={$durationPlan['scene_count']}
required_segments={$segmentsJson}

CURRENT USER REQUEST:
Idea: {$context['current_request']['idea']}
Title: {$context['current_request']['title']}

REGENERATION INSTRUCTION:
{$this->instructionLine($regenerationInstruction)}

OUTPUT CONTRACT (return exactly this JSON shape, with scenes matching required_segments timing):
{
  "title": "string",
  "logline": "string",
  "master_story": "string",
  "total_duration_seconds": {$durationPlan['total_duration_seconds']},
  "scene_duration_target_seconds": {$durationPlan['scene_duration_target_seconds']},
  "remainder_strategy": "{$durationPlan['remainder_strategy']}",
  "scenes": [
    {
      "sequence": 1,
      "start_second": 0,
      "end_second": 30,
      "duration_seconds": 30,
      "title": "string",
      "story": "string",
      "characters": ["string"],
      "location": "string",
      "dialogue": ["string"],
      "narration": "string",
      "visual_prompt": "string",
      "motion_prompt": "string",
      "audio_direction": "string",
      "continuity": {
        "previous_scene": "string",
        "next_scene": "string",
        "character_state": "string",
        "environment_state": "string"
      }
    }
  ]
}
PROMPT;

        return [
            'system' => $system,
            'prompt' => $prompt,
        ];
    }

    private function instructionLine(?string $instruction): string
    {
        if ($instruction === null || trim($instruction) === '') {
            return '(none — create or continue the plan from the current request)';
        }

        return trim($instruction);
    }
}
