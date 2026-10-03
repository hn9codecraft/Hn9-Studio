<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoryTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_arrange_trim_split_replace_duplicate_and_transition_without_a_provider(): void
    {
        Storage::fake('videos');
        Storage::fake('voice');
        Http::fake();
        [$owner, $project, $reel, $first, $second] = $this->videos();
        Storage::disk('voice')->put('story/narration.mp3', 'narration-bytes');
        $audio = $this->audio($reel, $first['scene']);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $first['version']->uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.clips.0.out_ms', 30000)
            ->assertJsonMissingPath('data.clips.0.path')
            ->assertJsonMissingPath('data.clips.0.disk');
        $this->assertSame('story/first.mp4', StoryTimelineClip::query()->orderBy('id')->value('path'));

        $audioPlaced = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/clips', [
                'media_kind' => 'audio',
                'source_id' => $audio->uuid,
            ])
            ->assertCreated();

        $videoId = $audioPlaced->json('data.clips.0.id');
        $audioId = $audioPlaced->json('data.clips.1.id');

        $reordered = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/reorder', [
                'ordered_ids' => [$audioId, $videoId],
            ])
            ->assertOk();
        $this->assertSame([$audioId, $videoId], array_column($reordered->json('data.clips'), 'id'));

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/reorder', [
                'ordered_ids' => [$videoId, $audioId],
            ])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel)."/clips/{$videoId}/trim", [
                'in_ms' => 1000,
                'out_ms' => 7000,
            ])
            ->assertOk()
            ->assertJsonPath('data.clips.0.in_ms', 1000)
            ->assertJsonPath('data.clips.0.out_ms', 7000);

        $split = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel)."/clips/{$videoId}/split", [
                'at_ms' => 4000,
            ])
            ->assertOk();
        $clips = $split->json('data.clips');
        $this->assertCount(3, $clips);
        $this->assertArrayNotHasKey('path', $clips[0]);
        $this->assertArrayNotHasKey('disk', $clips[0]);
        $this->assertSame(
            ['story/first.mp4', 'story/first.mp4'],
            StoryTimelineClip::query()->orderBy('position')->limit(2)->pluck('path')->all(),
        );
        $this->assertSame(1000, $clips[0]['in_ms']);
        $this->assertSame(4000, $clips[0]['out_ms']);
        $this->assertSame(4000, $clips[1]['in_ms']);
        $this->assertSame(7000, $clips[1]['out_ms']);
        $this->assertSame(1, StorySceneVersion::query()->where('uuid', $first['version']->uuid)->count());

        $replaced = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel)."/clips/{$clips[1]['id']}/replace", [
                'source_id' => $second['version']->uuid,
            ])
            ->assertOk();
        $this->assertArrayNotHasKey('path', $replaced->json('data.clips.1'));
        $this->assertSame(
            ['story/first.mp4', 'story/second.mp4'],
            StoryTimelineClip::query()->where('media_kind', 'video')->orderBy('position')->pluck('path')->all(),
        );

        $beforeFiles = Storage::disk('videos')->allFiles();
        $duplicated = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel)."/clips/{$clips[0]['id']}/duplicate")
            ->assertCreated();
        $this->assertCount(4, $duplicated->json('data.clips'));
        $this->assertSame($beforeFiles, Storage::disk('videos')->allFiles());

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/transitions', [
                'from_clip_id' => $duplicated->json('data.clips.0.id'),
                'to_clip_id' => $duplicated->json('data.clips.1.id'),
                'type' => 'dissolve',
                'duration_ms' => 500,
            ])
            ->assertOk()
            ->assertJsonPath('data.transitions.0.type', 'dissolve');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/transitions', [
                'from_clip_id' => $duplicated->json('data.clips.0.id'),
                'to_clip_id' => $duplicated->json('data.clips.2.id'),
                'type' => 'fade',
            ])
            ->assertStatus(422);

        $deleted = $this->actingAs($owner, 'sanctum')
            ->deleteJson($this->url($project, $reel)."/clips/{$clips[0]['id']}")
            ->assertOk();
        $this->assertNotContains($clips[0]['id'], array_column($deleted->json('data.clips'), 'id'));
        $this->assertNotNull(StorySceneVersion::query()->find($first['version']->id));
        $this->assertTrue(Storage::disk('videos')->exists('story/first.mp4'));
        $this->assertSame('narration-bytes', Storage::disk('voice')->get('story/narration.mp3'));

        Http::assertNothingSent();
    }

    public function test_cross_project_replace_is_rejected_and_non_owners_cannot_edit(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $first] = $this->videos();
        $foreign = $this->videos();
        $placed = $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $first['version']->uuid,
            ])
            ->assertCreated();
        $clipId = $placed->json('data.clips.0.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($project, $reel)."/clips/{$clipId}/replace", [
                'source_id' => $foreign[3]['version']->uuid,
            ])
            ->assertStatus(422);

        $this->assertSame('story/first.mp4', StoryTimelineClip::query()->first()?->path);
        $intruder = User::factory()->create();
        $this->actingAs($intruder, 'sanctum')
            ->getJson($this->url($project, $reel))
            ->assertForbidden();
        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->url($project, $reel)."/clips/{$clipId}/trim", [
                'in_ms' => 0,
                'out_ms' => 1000,
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_anonymous_cannot_read_the_timeline(): void
    {
        Http::fake();
        [, $project, $reel] = $this->videos();

        $this->getJson($this->url($project, $reel))->assertUnauthorized();
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

    private function audio(StoryReel $reel, StoryScene $scene): StorySceneAudio
    {
        return StorySceneAudio::query()->create([
            'story_workspace_id' => $reel->story_workspace_id,
            'story_scene_id' => $scene->id,
            'role' => 'narration',
            'status' => 'completed',
            'review_status' => 'approved',
            'disk' => 'voice',
            'path' => 'story/narration.mp3',
            'mime' => 'audio/mpeg',
        ]);
    }

    private function url(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/timeline";
    }
}
