<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Exceptions\StoryPlannerException;
use App\Story\Support\StoryPlanDurationCalculator;
use App\Story\Support\StoryPlanStructuredOutput;
use PHPUnit\Framework\TestCase;

final class StoryPlanDurationAndOutputTest extends TestCase
{
    public function test_exact_multiples_use_exact_strategy(): void
    {
        $result = (new StoryPlanDurationCalculator)->calculate(120);

        $this->assertSame('exact', $result['remainder_strategy']);
        $this->assertSame(4, $result['scene_count']);
        $this->assertSame(0, $result['segments'][0]['start_second']);
        $this->assertSame(120, $result['segments'][3]['end_second']);
    }

    public function test_remainder_uses_final_short_scene_strategy(): void
    {
        $result = (new StoryPlanDurationCalculator)->calculate(100);

        $this->assertSame('final_short_scene', $result['remainder_strategy']);
        $this->assertSame(4, $result['scene_count']);
        $this->assertSame(10, $result['segments'][3]['duration_seconds']);
        $this->assertSame(100, $result['segments'][3]['end_second']);
    }

    public function test_structured_output_rejects_missing_required_fields(): void
    {
        $this->expectException(StoryPlannerException::class);

        $calc = (new StoryPlanDurationCalculator)->calculate(30);
        (new StoryPlanStructuredOutput)->parseAndValidate(
            '{"title":"Only title","total_duration_seconds":30,"scenes":[]}',
            30,
            $calc['segments'],
        );
    }
}
