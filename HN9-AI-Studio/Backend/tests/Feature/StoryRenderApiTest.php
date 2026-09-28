<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoryRenderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_renders_stored_clips_into_one_private_file_without_a_provider(): void
    {
        Storage::fake('videos');
        Storage::fake('voice');
        Http::fake();
        [$owner, $project, $reel, $first, $second] = $this->videos();

        $placed = $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $first['version']->uuid,
            ])
            ->assertCreated();
        $firstClip = $placed->json('data.clips.0.id');
        $secondPlaced = $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $second['version']->uuid,
            ])
            ->assertCreated();
        $secondClip = $secondPlaced->json('data.clips.1.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel)."/clips/{$firstClip}/trim", [
                'in_ms' => 500,
                'out_ms' => 6000,
            ])
            ->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/transitions', [
                'from_clip_id' => $firstClip,
                'to_clip_id' => $secondClip,
                'type' => 'dissolve',
                'duration_ms' => 400,
            ])
            ->assertOk();

        $started = $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.mime', 'video/mp4')
            ->assertJsonPath('data.error_code', null);

        $path = $started->json('data.path');
        $this->assertIsString($path);
        $this->assertStringStartsWith('renders/', $path);
        $this->assertStringEndsWith('.mp4', $path);
        $this->assertNotSame('story/first.mp4', $path);
        $this->assertNotSame('story/second.mp4', $path);
        $this->assertNotEmpty($started->json('data.timeline_version'));
        $this->assertGreaterThan(strlen('first-bytes') + strlen('second-bytes'), $started->json('data.size_bytes'));

        $body = $this->actingAs($owner, 'sanctum')
            ->get($this->renders($project, $reel).'/'.$started->json('data.id').'/file')
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');
        $bytes = $body->streamedContent();
        $this->assertStringContainsString('ftyp', $bytes);
        $this->assertStringContainsString('first-bytes', $bytes);
        $this->assertStringContainsString('second-bytes', $bytes);
        $this->assertStringContainsString('dissolve', $bytes);
        $this->assertSame($bytes, Storage::disk('videos')->get($path));
        $this->assertSame('first-bytes', Storage::disk('videos')->get('story/first.mp4'));
        $this->assertSame('second-bytes', Storage::disk('videos')->get('story/second.mp4'));
        $this->assertFalse(Storage::disk('videos')->exists('renders/'.$started->json('data.id').'.partial'));

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->renders($project, $reel).'/'.$started->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.path', $path);

        Http::assertNothingSent();
    }

    public function test_missing_source_fails_without_marking_a_partial_file_final(): void
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
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_render_source_missing');

        $render = StoryFinalRender::query()->first();
        $this->assertNotNull($render);
        $this->assertSame('failed', $render->status);
        $this->assertNull($render->path);
        $this->assertNull($render->disk);
        $this->assertSame('story_render_source_missing', $render->error_code);
        $this->assertNotEmpty($render->timeline_version);
        foreach (Storage::disk('videos')->allFiles() as $file) {
            $this->assertFalse(str_starts_with($file, 'renders/'), $file);
        }
        $this->assertSame('first-bytes', Storage::disk('videos')->get('story/first.mp4'));

        Http::assertNothingSent();
    }

    public function test_empty_timeline_fails_with_a_stable_code(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel] = $this->videos();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_render_empty');

        $this->assertSame(0, StoryFinalRender::query()->count());
        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_read_the_final_file_and_anonymous_is_unauthorized(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first] = $this->videos();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $first['version']->uuid,
            ])
            ->assertCreated();
        $renderId = $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertCreated()
            ->json('data.id');

        $intruder = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $foreign = $this->videos();
        $this->actingAs($intruder, 'sanctum')
            ->get($this->renders($project, $reel).'/'.$renderId.'/file')
            ->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')
            ->getJson($this->renders($project, $reel).'/'.$renderId)
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->get($this->renders($foreign[1], $foreign[2]).'/'.$renderId.'/file')
            ->assertForbidden();
        $this->actingAs($owner, 'sanctum')
            ->getJson($this->renders($project, $reel).'/00000000-0000-4000-8000-000000000000')
            ->assertNotFound();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->get($this->renders($project, $reel).'/'.$renderId.'/file')
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_anonymous_cannot_start_or_download_a_render(): void
    {
        Http::fake();
        [, $project, $reel] = $this->videos();

        $this->postJson($this->renders($project, $reel))->assertUnauthorized();
        $this->getJson($this->renders($project, $reel).'/00000000-0000-4000-8000-000000000000')->assertUnauthorized();
        Http::assertNothingSent();
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
}
