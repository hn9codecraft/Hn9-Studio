<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryExport;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Story\Media\StoryMediaToolkit;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeStoryMediaToolkit;
use Tests\TestCase;
use ZipArchive;

final class StoryExportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('videos');
        Storage::fake('voice');
        Storage::fake('exports');
        Http::fake();
        $this->app->instance(StoryMediaToolkit::class, new FakeStoryMediaToolkit);
    }

    public function test_export_is_refused_until_the_final_render_is_approved(): void
    {
        [$owner, $project, $reel, $first] = $this->videos();
        $render = $this->render($owner, $project, $reel, [$first]);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->exportUrl($project, $reel, $render))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_export_render_not_approved');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renderUrl($project, $reel, $render).'/submit-review')
            ->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->exportUrl($project, $reel, $render))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_export_render_not_approved');

        $this->assertSame(0, StoryExport::query()->count());
        $this->assertSame([], Storage::disk('exports')->allFiles());
        Http::assertNothingSent();
    }

    public function test_owner_exports_a_real_zip_that_matches_stored_media_and_downloads_it(): void
    {
        [$owner, $project, $reel, $first, $second] = $this->videos();
        Storage::disk('voice')->put('story/narration.mp3', 'narration-bytes');
        $audio = $this->audio($reel, $first['scene']);
        $render = $this->render($owner, $project, $reel, [$first, $second], $audio);
        $this->approve($owner, $project, $reel, $render);

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson($this->exportUrl($project, $reel, $render))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.render_id', $render)
            ->assertJsonPath('data.output_url', null)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');
        $exportId = $created->json('data.id');
        $this->assertGreaterThan(0, $created->json('data.size'));
        $body = (string) $created->getContent();
        $this->assertStringNotContainsString(Storage::disk('exports')->path(''), $body);
        $this->assertStringNotContainsString('story/first.mp4', $body);

        $export = StoryExport::query()->where('uuid', $exportId)->firstOrFail();
        $this->assertSame('exports', $export->disk);
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->completed_at);
        $this->assertStringStartsWith('story/'.$exportId.'/', (string) $export->path);
        $this->assertTrue(Storage::disk('exports')->exists((string) $export->path));
        $this->assertSame((int) $export->size, Storage::disk('exports')->size((string) $export->path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('exports')->path((string) $export->path)) === true);
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }
        $root = str_replace('-story-export.zip', '', (string) $export->filename);
        foreach ([
            'final/final-render.mp4',
            'scenes/scene-01/script.txt',
            'scenes/scene-01/video-v1.mp4',
            'scenes/scene-02/script.txt',
            'scenes/scene-02/video-v1.mp4',
            'audio/audio-01.mp3',
            'metadata/story.json',
            'README.txt',
        ] as $expected) {
            $this->assertContains($root.'/'.$expected, $entries);
        }
        foreach ($entries as $entry) {
            $this->assertStringStartsWith($root.'/', $entry);
            $this->assertStringNotContainsString('..', $entry);
            $this->assertStringNotContainsString(':', $entry);
        }

        $finalRender = StoryFinalRender::query()->where('uuid', $render)->firstOrFail();
        $this->assertSame(
            Storage::disk('videos')->get((string) $finalRender->path),
            $zip->getFromName($root.'/final/final-render.mp4'),
        );
        $this->assertSame('first-bytes', $zip->getFromName($root.'/scenes/scene-01/video-v1.mp4'));
        $this->assertSame('second-bytes', $zip->getFromName($root.'/scenes/scene-02/video-v1.mp4'));
        $this->assertSame('narration-bytes', $zip->getFromName($root.'/audio/audio-01.mp3'));
        $this->assertStringContainsString('Scene 1', (string) $zip->getFromName($root.'/scenes/scene-01/script.txt'));

        $metadataJson = (string) $zip->getFromName($root.'/metadata/story.json');
        $metadata = json_decode($metadataJson, true);
        $this->assertSame($render, $metadata['final_render']['id']);
        $this->assertSame('approved', $metadata['final_render']['review_status']);
        $this->assertSame('final/final-render.mp4', $metadata['final_render']['file']);
        $this->assertCount(2, $metadata['scenes']);
        $this->assertSame('scenes/scene-01/script.txt', $metadata['scenes'][0]['script']);
        foreach (['story/first.mp4', 'story/narration.mp3', 'renders/', 'test-key', 'api_key', 'password', Storage::disk('videos')->path('')] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $metadataJson);
        }
        $zip->close();

        $download = $this->actingAs($owner, 'sanctum')
            ->get($this->reelUrl($project, $reel).'/exports/'.$exportId.'/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');
        $this->assertSame(Storage::disk('exports')->get((string) $export->path), $download->streamedContent());

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->reelUrl($project, $reel).'/exports/'.$exportId)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.path');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->exportUrl($project, $reel, $render))
            ->assertOk()
            ->assertJsonPath('data.id', $exportId);
        $this->assertSame(1, StoryExport::query()->count());

        Http::assertNothingSent();
    }

    public function test_other_users_and_anonymous_callers_cannot_download_the_export(): void
    {
        [$owner, $project, $reel, $first] = $this->videos();
        $render = $this->render($owner, $project, $reel, [$first]);
        $this->approve($owner, $project, $reel, $render);
        $exportId = $this->actingAs($owner, 'sanctum')
            ->postJson($this->exportUrl($project, $reel, $render))
            ->assertCreated()
            ->json('data.id');
        $download = $this->reelUrl($project, $reel).'/exports/'.$exportId.'/download';

        $intruder = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $this->actingAs($intruder, 'sanctum')->get($download)->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->getJson($this->reelUrl($project, $reel).'/exports/'.$exportId)->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->postJson($this->exportUrl($project, $reel, $render))->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->get($download)->assertForbidden();

        [$foreignOwner, $foreignProject, $foreignReel] = $this->videos();
        $this->actingAs($foreignOwner, 'sanctum')
            ->get($this->reelUrl($foreignProject, $foreignReel).'/exports/'.$exportId.'/download')
            ->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->get($this->reelUrl($foreignProject, $foreignReel).'/exports/'.$exportId.'/download')
            ->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->get($download)->assertOk();

        $this->assertSame(1, StoryExport::query()->count());
        Http::assertNothingSent();
    }

    public function test_anonymous_export_requests_are_unauthorized(): void
    {
        [, $project, $reel] = $this->videos();
        $missing = '00000000-0000-4000-8000-000000000000';

        $this->postJson($this->reelUrl($project, $reel).'/renders/'.$missing.'/exports')->assertUnauthorized();
        $this->getJson($this->reelUrl($project, $reel).'/exports/'.$missing)->assertUnauthorized();
        $this->get($this->reelUrl($project, $reel).'/exports/'.$missing.'/download', ['Accept' => 'application/json'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    /**
     * @param  list<array{scene: StoryScene, version: StorySceneVersion}>  $videos
     */
    private function render(User $owner, Project $project, StoryReel $reel, array $videos, ?StorySceneAudio $audio = null): string
    {
        foreach ($videos as $video) {
            $this->actingAs($owner, 'sanctum')
                ->postJson($this->reelUrl($project, $reel).'/timeline/clips', [
                    'media_kind' => 'video',
                    'source_id' => $video['version']->uuid,
                ])
                ->assertCreated();
        }
        if ($audio !== null) {
            $this->actingAs($owner, 'sanctum')
                ->postJson($this->reelUrl($project, $reel).'/timeline/clips', [
                    'media_kind' => 'audio',
                    'source_id' => $audio->uuid,
                ])
                ->assertCreated();
        }

        return (string) $this->actingAs($owner, 'sanctum')
            ->postJson($this->reelUrl($project, $reel).'/renders')
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->json('data.id');
    }

    private function approve(User $owner, Project $project, StoryReel $reel, string $render): void
    {
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renderUrl($project, $reel, $render).'/submit-review')
            ->assertOk();
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renderUrl($project, $reel, $render).'/approve')
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved');
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: array{scene: StoryScene, version: StorySceneVersion}, 4: array{scene: StoryScene, version: StorySceneVersion}}
     */
    private function videos(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['name' => 'Story Export Demo']);
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
            'story' => 'Approved story text for scene '.$sequence.'.',
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
            'disk' => 'voice',
            'path' => 'story/narration.mp3',
            'mime' => 'audio/mpeg',
        ]);
    }

    private function reelUrl(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}";
    }

    private function renderUrl(Project $project, StoryReel $reel, string $render): string
    {
        return $this->reelUrl($project, $reel).'/renders/'.$render;
    }

    private function exportUrl(Project $project, StoryReel $reel, string $render): string
    {
        return $this->renderUrl($project, $reel, $render).'/exports';
    }
}
