<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Support\StoryPlanDurationCalculator;
use App\Story\Support\StoryPlannerPromptBuilder;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Thirty seconds is the planner's pacing target, never a limit on scene length.
 */
final class StoryPlanningWordingTest extends TestCase
{
    private const RULE_PHRASES = [
        '/planned in 30-second scenes/i',
        '/scenes? (?:are|is) (?:always |exactly )?30 seconds/i',
        '/30-second scene (?:rule|limit)/i',
        '/each scene (?:is|lasts) 30 seconds/i',
    ];

    public function test_user_facing_copy_describes_thirty_seconds_as_story_pacing(): void
    {
        $sources = $this->sources(dirname(__DIR__, 3).'/Frontend/src', ['js', 'jsx']);
        if ($sources === []) {
            $this->markTestSkipped('The frontend sources are not checked out next to the backend.');
        }

        foreach ($sources as $path => $source) {
            foreach (self::RULE_PHRASES as $phrase) {
                $this->assertDoesNotMatchRegularExpression($phrase, $source, $path);
            }
        }

        $planner = $sources[$this->find($sources, 'StudioScenePlanner.jsx')];
        $this->assertStringContainsString('roughly 30-second story beats', $planner);
        $this->assertStringContainsString('Approve and prepare production', $planner);
        $this->assertStringContainsString('Approving this story will prepare its', $planner);
        $this->assertStringContainsString('Story approved. Your production scenes are ready.', $planner);
        $this->assertStringContainsString('Continue to Scenes', $planner);
        $this->assertStringContainsString('roughly 30-second story beats', $sources[$this->find($sources, 'StudioStoryStep.jsx')]);
    }

    public function test_backend_messages_carry_no_thirty_second_scene_rule(): void
    {
        foreach ($this->sources(dirname(__DIR__, 2).'/app', ['php']) as $path => $source) {
            foreach (self::RULE_PHRASES as $phrase) {
                $this->assertDoesNotMatchRegularExpression($phrase, $source, $path);
            }
        }
    }

    public function test_the_planner_contract_keeps_its_thirty_second_pacing_target(): void
    {
        $this->assertSame(30, StoryPlanDurationCalculator::SCENE_TARGET_SECONDS);
        $source = (string) file_get_contents((new \ReflectionClass(StoryPlannerPromptBuilder::class))->getFileName());

        $this->assertStringContainsString('"scene_duration_target_seconds"', $source);
    }

    /**
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    private function sources(string $root, array $extensions): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $sources
     */
    private function find(array $sources, string $name): string
    {
        foreach (array_keys($sources) as $path) {
            if (basename($path) === $name) {
                return $path;
            }
        }

        $this->fail("{$name} was not found.");
    }
}
