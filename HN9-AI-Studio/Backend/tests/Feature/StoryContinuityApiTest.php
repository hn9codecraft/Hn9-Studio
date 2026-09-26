<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StoryContinuityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_includes_each_source_and_the_immediate_previous_scene(): void
    {
        Http::fake();
        [$user, $project, $reel, $second] = $this->readyReel();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$second->uuid}/continuity")
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.story_bible.concept', 'A boy finds a map')
            ->assertJsonPath('data.characters.0.name', 'Aarav')
            ->assertJsonPath('data.style_bible.visual_style', 'Painted dusk')
            ->assertJsonPath('data.previous_scene.sequence', 1)
            ->assertJsonPath('data.previous_scene.title', 'Opening');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('disk', $body);
        $this->assertStringNotContainsString('path', $body);
        Http::assertNothingSent();
    }

    public function test_missing_sources_return_stable_codes_without_invented_text(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
            'title' => 'Only scene',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}/continuity")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.story_bible', null)
            ->assertJsonPath('data.previous_scene', null)
            ->assertJsonPath('data.issues.0', 'story_bible_missing');

        Http::assertNothingSent();
    }

    public function test_non_owner_and_anonymous_cannot_read_continuity(): void
    {
        Http::fake();
        [$user, $project, $reel, $second] = $this->readyReel();
        $intruder = User::factory()->create();

        $this->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$second->uuid}/continuity")
            ->assertUnauthorized();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$second->uuid}/continuity")
            ->assertForbidden();

        $other = Project::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$other->uuid}/reels/{$reel->uuid}/scenes/{$second->uuid}/continuity")
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene}
     */
    private function readyReel(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        StoryBible::factory()->create([
            'story_workspace_id' => $workspace->id,
            'concept' => 'A boy finds a map',
        ]);
        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => $workspace->id,
            'name' => 'Aarav',
        ]);
        StoryCharacterReference::factory()->create(['story_character_id' => $character->id]);
        $style = StoryStyleBible::factory()->create([
            'story_workspace_id' => $workspace->id,
            'visual_style' => 'Painted dusk',
        ]);
        StoryStyleReference::factory()->create(['story_style_bible_id' => $style->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
            'title' => 'Opening',
        ]);
        $second = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 2,
            'title' => 'The map',
        ]);

        return [$user, $project, $reel, $second];
    }
}
