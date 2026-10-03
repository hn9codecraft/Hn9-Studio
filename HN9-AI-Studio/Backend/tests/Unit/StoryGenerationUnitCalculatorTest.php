<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Exceptions\StoryProductionPlanException;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Support\StoryGenerationUnitSlot;
use App\Story\Support\StorySceneTimingNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Pure tests: no application container and no database.
 */
final class StoryGenerationUnitCalculatorTest extends TestCase
{
    private StoryGenerationUnitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new StoryGenerationUnitCalculator;
    }

    /**
     * @return array<string, array{0: int, 1: list<int>}>
     */
    public static function durations(): array
    {
        return [
            'minimum 1 sec' => [1, [1]],
            '5 sec' => [5, [5]],
            '7 sec' => [7, [7]],
            '9 sec' => [9, [9]],
            '10 sec' => [10, [10]],
            '11 sec' => [11, [10, 1]],
            '17 sec' => [17, [10, 7]],
            '20 sec' => [20, [10, 10]],
            '29 sec' => [29, [10, 10, 9]],
            '30 sec' => [30, [10, 10, 10]],
            '31 sec' => [31, [10, 10, 10, 1]],
            '39 sec' => [39, [10, 10, 10, 9]],
            '40 sec' => [40, [10, 10, 10, 10]],
            '41 sec' => [41, [10, 10, 10, 10, 1]],
            '47 sec' => [47, [10, 10, 10, 10, 7]],
            '49 sec' => [49, [10, 10, 10, 10, 9]],
            '50 sec' => [50, [10, 10, 10, 10, 10]],
            '59 sec' => [59, [10, 10, 10, 10, 10, 9]],
            '60 sec' => [60, [10, 10, 10, 10, 10, 10]],
            '61 sec' => [61, [10, 10, 10, 10, 10, 10, 1]],
            '90 sec' => [90, array_fill(0, 9, 10)],
            '120 sec' => [120, array_fill(0, 12, 10)],
            'maximum minus one 3599 sec' => [3599, [...array_fill(0, 359, 10), 9]],
            'maximum 3600 sec' => [3600, array_fill(0, 360, 10)],
        ];
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('durations')]
    public function test_split_produces_exact_units(int $seconds, array $expected): void
    {
        $slots = $this->calculator->split($seconds);

        $this->assertSame($expected, array_map(static fn (StoryGenerationUnitSlot $slot): int => $slot->durationSeconds, $slots));
        $this->assertSame(count($expected), $this->calculator->unitCount($seconds));
        $this->assertSame(range(1, count($expected)), array_map(static fn (StoryGenerationUnitSlot $slot): int => $slot->sequence, $slots));

        $start = 0;
        foreach ($slots as $index => $slot) {
            $this->assertSame($start, $slot->startSecond, "unit {$slot->sequence} start");
            $this->assertSame($start + $expected[$index], $slot->endSecond(), "unit {$slot->sequence} end");
            $start = $slot->endSecond();
        }
        $this->assertSame($seconds, $start);
        $this->assertSame($seconds, array_sum($expected));
    }

    public function test_47_seconds_matches_the_documented_layout(): void
    {
        $layout = array_map(
            static fn (StoryGenerationUnitSlot $slot): array => [$slot->sequence, $slot->startSecond, $slot->durationSeconds, $slot->endSecond()],
            $this->calculator->split(47),
        );

        $this->assertSame([
            [1, 0, 10, 10],
            [2, 10, 10, 20],
            [3, 20, 10, 30],
            [4, 30, 10, 40],
            [5, 40, 7, 47],
        ], $layout);
    }

    public function test_every_valid_duration_satisfies_every_invariant(): void
    {
        $unit = StoryGenerationUnitCalculator::UNIT_SECONDS;

        for ($seconds = StorySceneTimingNormalizer::MIN_DURATION_SECONDS; $seconds <= StorySceneTimingNormalizer::MAX_DURATION_SECONDS; $seconds++) {
            $slots = $this->calculator->split($seconds);

            if (count($slots) !== (int) ceil($seconds / $unit)) {
                $this->fail("{$seconds}s: unit count");
            }
            if ($slots[0]->startSecond !== 0) {
                $this->fail("{$seconds}s: first start");
            }

            $sum = 0;
            $previousEnd = 0;
            foreach ($slots as $index => $slot) {
                if ($slot->sequence !== $index + 1) {
                    $this->fail("{$seconds}s: sequence gap at {$index}");
                }
                if ($slot->durationSeconds <= 0 || $slot->durationSeconds > $unit) {
                    $this->fail("{$seconds}s: unit {$slot->sequence} duration {$slot->durationSeconds}");
                }
                if ($slot->startSecond !== $previousEnd) {
                    $this->fail("{$seconds}s: gap or overlap before unit {$slot->sequence}");
                }
                if ($index < count($slots) - 1 && $slot->durationSeconds !== $unit) {
                    $this->fail("{$seconds}s: only the last unit may be short");
                }
                $previousEnd = $slot->endSecond();
                $sum += $slot->durationSeconds;
            }

            if ($previousEnd !== $seconds || $sum !== $seconds) {
                $this->fail("{$seconds}s: coverage {$previousEnd}/{$sum}");
            }
        }

        $this->addToAssertionCount(StorySceneTimingNormalizer::MAX_DURATION_SECONDS);
    }

    public function test_split_is_deterministic(): void
    {
        $this->assertEquals($this->calculator->split(1234), $this->calculator->split(1234));
    }

    public function test_the_unit_rule_is_ten_seconds_and_respects_the_existing_scene_limits(): void
    {
        $this->assertSame(10, StoryGenerationUnitCalculator::UNIT_SECONDS);
        $this->assertSame(StoryGenerationUnitCalculator::UNIT_SECONDS, $this->calculator->unitSeconds());
        $this->assertSame(1, StorySceneTimingNormalizer::MIN_DURATION_SECONDS);
        $this->assertSame(3600, StorySceneTimingNormalizer::MAX_DURATION_SECONDS);
    }

    public function test_remainder_flag_marks_only_the_short_last_unit(): void
    {
        $slots = $this->calculator->split(17);

        $this->assertFalse($slots[0]->isRemainder(StoryGenerationUnitCalculator::UNIT_SECONDS));
        $this->assertTrue($slots[1]->isRemainder(StoryGenerationUnitCalculator::UNIT_SECONDS));
        $this->assertFalse($this->calculator->split(30)[2]->isRemainder(StoryGenerationUnitCalculator::UNIT_SECONDS));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidDurations(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'negative ten' => [-10],
            'above maximum' => [3601],
            'huge' => [PHP_INT_MAX],
            'most negative' => [PHP_INT_MIN],
        ];
    }

    #[DataProvider('invalidDurations')]
    public function test_invalid_durations_are_rejected(int $seconds): void
    {
        try {
            $this->calculator->split($seconds);
            $this->fail('Expected a rejected duration.');
        } catch (StoryProductionPlanException $exception) {
            $this->assertSame('story_production_invalid_duration', $exception->errorCode());
            $this->assertSame(422, $exception->statusCode());
        }

        $this->expectException(StoryProductionPlanException::class);
        $this->calculator->unitCount($seconds);
    }

    public function test_fractional_seconds_are_not_accepted(): void
    {
        // Durations are whole seconds everywhere in the story domain.
        $this->expectException(TypeError::class);
        $this->calculator->split(7.5); // @phpstan-ignore argument.type
    }

    /**
     * @return array<string, array{0: list<array{0: int, 1: int, 2: int}>, 1: int}>
     */
    public static function brokenLayouts(): array
    {
        return [
            'empty' => [[], 10],
            'gap' => [[[1, 0, 10], [2, 11, 5]], 16],
            'overlap' => [[[1, 0, 10], [2, 9, 5]], 14],
            'zero length' => [[[1, 0, 10], [2, 10, 0]], 10],
            'longer than a unit' => [[[1, 0, 11]], 11],
            'sequence skips' => [[[1, 0, 10], [3, 10, 5]], 15],
            'sequence starts at zero' => [[[0, 0, 10]], 10],
            'does not start at zero' => [[[1, 1, 9]], 10],
            'total too short' => [[[1, 0, 10]], 12],
            'total too long' => [[[1, 0, 10], [2, 10, 10]], 15],
        ];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $rows
     */
    #[DataProvider('brokenLayouts')]
    public function test_coverage_check_rejects_broken_layouts(array $rows, int $sceneSeconds): void
    {
        $slots = array_map(static fn (array $row): StoryGenerationUnitSlot => new StoryGenerationUnitSlot($row[0], $row[1], $row[2]), $rows);

        try {
            $this->calculator->assertCoverage($slots, $sceneSeconds);
            $this->fail('Expected the layout to be rejected.');
        } catch (StoryProductionPlanException $exception) {
            $this->assertSame('story_production_invalid_units', $exception->errorCode());
        }
    }
}
