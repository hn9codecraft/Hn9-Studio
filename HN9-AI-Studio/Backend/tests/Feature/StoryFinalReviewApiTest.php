<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryFinalRenderReview;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Story\Media\StoryMediaToolkit;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeStoryMediaToolkit;
use Tests\TestCase;

final class StoryFinalReviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(StoryMediaToolkit::class, new FakeStoryMediaToolkit);
    }

    public function test_submit_and_approve_keep_the_render_file_and_do_not_call_a_provider(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first] = $this->videos();
        $render = $this->rendered($owner, $project, $reel, $first);
        $jobs = StoryVideoGenerationJob::query()->count();
        $path = $render['path'];

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/approve')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_review_invalid_transition');

        $submitted = $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/submit-review')
            ->assertOk()
            ->assertJsonPath('data.review_status', 'pending_review')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.events.0.action', 'submitted');

        $approved = $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/approve')
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.path', $path)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.events.1.action', 'approved');

        $this->assertSame($submitted->json('data.path'), $approved->json('data.path'));
        $body = $this->actingAs($owner, 'sanctum')
            ->get($this->review($project, $reel, $render['id']).'/file')
            ->assertOk();
        $this->assertStringContainsString('first-bytes', $body->streamedContent());
        $this->assertSame($jobs, StoryVideoGenerationJob::query()->count());
        $this->assertSame('first-bytes', Storage::disk('videos')->get('story/first.mp4'));
        Http::assertNothingSent();
    }

    public function test_rework_records_a_comment_and_target_without_generating_video(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first] = $this->videos();
        $foreign = $this->videos();
        $render = $this->rendered($owner, $project, $reel, $first);
        $jobs = StoryVideoGenerationJob::query()->count();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/submit-review')
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/needs-rework', [
                'target_kind' => 'scene_version',
                'target_id' => $first['version']->uuid,
            ])
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/needs-rework', [
                'comment' => 'Change the opening scene.',
                'target_kind' => 'scene_version',
                'target_id' => $foreign[3]['version']->uuid,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_review_invalid_target');

        $reworked = $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/needs-rework', [
                'comment' => 'Change the opening scene.',
                'target_kind' => 'scene_version',
                'target_id' => $first['version']->uuid,
            ])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'needs_rework')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.events.1.action', 'needs_rework')
            ->assertJsonPath('data.events.1.comment', 'Change the opening scene.')
            ->assertJsonPath('data.events.1.target_kind', 'scene_version')
            ->assertJsonPath('data.events.1.target_id', $first['version']->uuid);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/submit-review')
            ->assertOk()
            ->assertJsonPath('data.review_status', 'pending_review');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/needs-rework', [
                'comment' => 'Reorder the timeline.',
                'target_kind' => 'timeline',
                'target_id' => $render['timeline_id'],
            ])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'needs_rework')
            ->assertJsonPath('data.events.3.target_kind', 'timeline');

        $this->assertSame($render['path'], $reworked->json('data.path'));
        $this->assertTrue(Storage::disk('videos')->exists($render['path']));
        $this->assertSame($jobs, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_render_that_is_not_complete_cannot_be_submitted(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first, $second] = $this->videos();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $first['version']->uuid,
            ])
            ->assertCreated();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $second['version']->uuid,
            ])
            ->assertCreated();
        Storage::disk('videos')->delete('story/second.mp4');
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertStatus(422);

        $failed = \App\Story\Models\StoryFinalRender::query()->first();
        $this->assertNotNull($failed);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $failed->uuid).'/submit-review')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_render_not_ready');
        $this->assertSame(0, StoryFinalRenderReview::query()->count());
        $this->assertSame('draft', $failed->fresh()?->review_status);
        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_approve_another_projects_render(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first] = $this->videos();
        $render = $this->rendered($owner, $project, $reel, $first);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/submit-review')
            ->assertOk();

        $intruder = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $foreign = $this->videos();
        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/approve')
            ->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/approve')
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->review($foreign[1], $foreign[2], $render['id']).'/approve')
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->getJson($this->review($project, $reel, '00000000-0000-4000-8000-000000000000'))
            ->assertNotFound();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson($this->review($project, $reel, $render['id']).'/approve')
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved');

        Http::assertNothingSent();
    }

    public function test_anonymous_review_requests_are_unauthorized(): void
    {
        Http::fake();
        [, $project, $reel] = $this->videos();
        $url = $this->review($project, $reel, '00000000-0000-4000-8000-000000000000');

        $this->postJson($url.'/submit-review')->assertUnauthorized();
        $this->postJson($url.'/approve')->assertUnauthorized();
        $this->postJson($url.'/needs-rework', [
            'comment' => 'Change it.',
            'target_kind' => 'timeline',
            'target_id' => '00000000-0000-4000-8000-000000000001',
        ])->assertUnauthorized();
        Http::assertNothingSent();
    }

    /**
     * @param  array{scene: StoryScene, version: StorySceneVersion}  $first
     * @return array{id: string, path: string, timeline_id: string}
     */
    private function rendered(User $owner, Project $project, StoryReel $reel, array $first): array
    {
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $first['version']->uuid,
            ])
            ->assertCreated();

        $started = $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'draft');

        return [
            'id' => $started->json('data.id'),
            'path' => $started->json('data.path'),
            'timeline_id' => $started->json('data.timeline_id'),
        ];
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: array{scene: StoryScene, version: StorySceneVersion}, 4?: array{scene: StoryScene, version: StorySceneVersion}}
     */
    private function videos(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $first = $this->storedVideo($workspace, $reel, 1, 'story/first.mp4', 'first-bytes');
        $second = $this->storedVideo($workspace, $reel, 2, 'story/second.mp4', 'second-bytes');

        return [$owner, $project, $reel, $first, $second];
    }

    /**
     * @return array{scene: StoryScene, version: StorySceneVersion}
     */
    private function storedVideo(StoryWorkspace $workspace, StoryReel $reel, int $sequence, string $path, string $bytes): array
    {
        Storage::disk('videos')->put($path, $bytes);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => $sequence,
        ]);
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => 1,
            'status' => 'approved',
            'title' => 'Scene '.$sequence,
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'story_reel_id' => $reel->id,
            'story_scene_id' => $scene->id,
            'status' => 'completed',
            'provider_metadata' => [
                'version_id' => $version->uuid,
                'storage' => [
                    'disk' => 'videos',
                    'path' => $path,
                    'mime' => 'video/mp4',
                ],
            ],
        ]);

        return ['scene' => $scene, 'version' => $version];
    }

    private function timeline(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/timeline";
    }

    private function renders(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/renders";
    }

    private function review(Project $project, StoryReel $reel, string $renderId): string
    {
        return $this->renders($project, $reel).'/'.$renderId;
    }
}
