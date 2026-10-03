<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryGenerationAttempt;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoryReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_comments_then_submits_and_approves_without_sql_status_writes(): void
    {
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->scene();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/comments', [
                'body' => '<script>alert(1)</script>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', '<script>alert(1)</script>');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/approve', [])
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/submit-review', [
                'comment' => 'Ready for review',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/approve', [
                'comment' => 'Approved by owner',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.output_url', null);

        $this->assertSame('approved', StorySceneVersion::query()->first()?->status);
        Http::assertNothingSent();
    }

    public function test_admin_can_rework_another_project_and_a_reviewer_cannot(): void
    {
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->scene();
        $admin = User::factory()->admin()->create();
        $reviewer = User::factory()->reviewer()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/submit-review', [])
            ->assertOk();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/approve', [])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/needs-rework', [
                'comment' => 'Change the opening line.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_rework');
    }

    public function test_regenerate_without_a_video_provider_opens_no_version_and_stores_no_file(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first] = $this->scene();
        $second = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 2,
            'title' => 'Second',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $second).'/regenerate', [
                'comment' => 'Only this scene',
            ])
            ->assertStatus(501);

        $this->assertSame(0, StorySceneVersion::query()->where('story_scene_id', $first->id)->count());
        $this->assertSame(0, StorySceneVersion::query()->where('story_scene_id', $second->id)->count());
        $attempt = StoryGenerationAttempt::query()->sole();
        $this->assertSame('not_connected', $attempt->status);
        $this->assertSame('video', $attempt->kind);
        $this->assertSame($second->id, $attempt->story_scene_id);
        $this->assertSame([], Storage::disk('videos')->allFiles());
        Http::assertNothingSent();

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->sceneUrl($project, $reel, $second).'/preview')
            ->assertOk()
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.has_file', false);
    }

    public function test_non_owner_and_anonymous_cannot_review_or_preview(): void
    {
        Http::fake();
        [, $project, $reel, $scene] = $this->scene();
        $intruder = User::factory()->create();

        $this->postJson($this->sceneUrl($project, $reel, $scene).'/comments', ['body' => 'no'])
            ->assertUnauthorized();
        $this->getJson($this->sceneUrl($project, $reel, $scene).'/preview')
            ->assertUnauthorized();
        $this->get($this->sceneUrl($project, $reel, $scene).'/file')
            ->assertUnauthorized();

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/regenerate', [])
            ->assertForbidden();
        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/approve", [])
            ->assertForbidden();
    }

    public function test_owner_can_submit_and_approve_a_reel(): void
    {
        Http::fake();
        [$owner, $project, $reel] = $this->scene();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/submit-review", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/approve", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene}
     */
    private function scene(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id, 'title' => 'Reel']);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
            'title' => 'Opening',
            'visual_prompt' => 'A quiet street',
        ]);

        return [$owner, $project, $reel, $scene];
    }

    private function sceneUrl(Project $project, StoryReel $reel, StoryScene $scene): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}";
    }
}
