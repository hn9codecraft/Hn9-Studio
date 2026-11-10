<?php

declare(strict_types=1);

return [
    'speech_roles' => ['voice', 'narration', 'dialogue'],
    'lead_in_ms' => (int) env('STORY_VOICE_TIMING_LEAD_IN_MS', 300),
    'tail_ms' => (int) env('STORY_VOICE_TIMING_TAIL_MS', 500),
    'min_scene_seconds' => (int) env('STORY_VOICE_TIMING_MIN_SCENE_SECONDS', 3),
    'max_scene_seconds' => (int) env('STORY_VOICE_TIMING_MAX_SCENE_SECONDS', 300),
    'round_to_options' => [1, 5],
    'default_round_to' => (int) env('STORY_VOICE_TIMING_ROUND_TO', 1),
    'total_drift_warning_percent' => 15,
];
