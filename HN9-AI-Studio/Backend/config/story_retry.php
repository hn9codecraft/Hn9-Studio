<?php

declare(strict_types=1);

return [
    // A user retry is a new attempt. Zero is not treated as unlimited.
    'max_user_retries_per_unit' => (int) env('STORY_RETRY_MAX_PER_UNIT', 5),
];
