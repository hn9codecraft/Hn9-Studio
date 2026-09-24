<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExportStatus;
use App\Enums\ImageStatus;
use App\Enums\ProjectStatus;
use App\Enums\VideoStatus;
use App\Exceptions\ExportException;
use App\Models\Export;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use App\Services\ExportPackageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class ProjectExportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('images');
        Storage::fake('videos');
        Storage::fake('exports');
    }

    public function test_ready_project_reports_approved_current_assets(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.can_finalize', true)
            ->assertJsonPath('data.can_export', false)
            ->assertJsonPath('data.workflow_state', 'ready_to_finalize')
            ->assertJsonPath('data.scripts.0.title', 'Launch script')
            ->assertJsonPath('data.images.0.title', 'Hero image')
            ->assertJsonPath('data.videos.0.title', 'Hero video');
    }

    public function test_pending_image_is_not_ready(): void
    {
        [$user, $project] = $this->readyProject();
        $project->images()->update(['status' => ImageStatus::PendingReview->value]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.workflow_state', 'not_ready')
            ->assertJsonPath('data.issues.0.code', 'image_not_approved');
    }

    public function test_needs_rework_video_is_not_ready(): void
    {
        [$user, $project] = $this->readyProject();
        $project->videos()->update(['status' => VideoStatus::NeedsRework->value]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'video_not_approved');
    }

    public function test_failed_image_is_not_ready(): void
    {
        [$user, $project] = $this->readyProject();
        $project->images()->update(['status' => ImageStatus::Failed->value]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'image_not_approved');
    }

    public function test_processing_video_blocks_export_even_when_another_is_approved(): void
    {
        [$user, $project] = $this->readyProject();

        Video::factory()->for($project)->create([
            'title' => 'Still rendering',
            'status' => VideoStatus::Processing->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'video_processing');
    }

    public function test_missing_approved_image_file_is_not_ready(): void
    {
        [$user, $project] = $this->readyProject();
        $image = $project->images()->firstOrFail();
        Storage::disk('images')->delete($image->file?->path ?? '');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.code', 'image_file_missing');
    }

    public function test_older_approved_versions_are_excluded_from_selection(): void
    {
        [$user, $project] = $this->readyProject();

        $oldScript = $project->scripts()->firstOrFail();
        Script::factory()->for($project)->approved()->create([
            'title' => 'Current script',
            'body' => 'The current approved script body.',
            'parent_script_id' => $oldScript->getKey(),
        ]);

        $oldImage = $project->images()->firstOrFail();
        $currentImage = Image::factory()->for($project)->create([
            'title' => 'Current image',
            'status' => ImageStatus::Approved->value,
            'parent_image_id' => $oldImage->getKey(),
        ]);
        $this->storeImage($currentImage, 'CURRENT-IMAGE');

        $oldVideo = $project->videos()->firstOrFail();
        $currentVideo = Video::factory()->for($project)->create([
            'title' => 'Current video',
            'status' => VideoStatus::Approved->value,
            'parent_video_id' => $oldVideo->getKey(),
        ]);
        $this->storeVideo($currentVideo, 'CURRENT-VIDEO');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $export = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->assertJsonPath('data.status', ExportStatus::Completed->value)
            ->json('data');

        $this->assertArrayNotHasKey('path', $export);
        $this->assertArrayNotHasKey('disk', $export);

        $zip = $this->openStoredZip($export['id']);
        $root = 'launch-reel';

        $this->assertNotFalse($zip->locateName($root.'/script/final-script.txt'));
        $this->assertSame(
            "Current script\n\nThe current approved script body.\n",
            $zip->getFromName($root.'/script/final-script.txt'),
        );
        $this->assertSame('CURRENT-IMAGE', $zip->getFromName($root.'/images/image-01.png'));
        $this->assertSame('CURRENT-VIDEO', $zip->getFromName($root.'/videos/video-01.mp4'));
        $this->assertStringNotContainsString('Launch script', (string) $zip->getFromName($root.'/script/final-script.txt'));
        $this->assertStringNotContainsString('PNG-BYTES', (string) $zip->getFromName($root.'/images/image-01.png'));
        $this->assertStringNotContainsString('MP4-BYTES', (string) $zip->getFromName($root.'/videos/video-01.mp4'));

        $manifest = json_decode((string) $zip->getFromName($root.'/metadata/project.json'), true);
        $this->assertSame($project->uuid, $manifest['project']['id']);
        $this->assertSame('Current script', $manifest['assets']['scripts'][0]['title']);
        $this->assertSame('Current image', $manifest['assets']['images'][0]['title']);
        $this->assertSame('Current video', $manifest['assets']['videos'][0]['title']);
        $this->assertArrayNotHasKey('provider_job_id', $manifest['assets']['videos'][0]);
        $zip->close();
    }

    public function test_unapproved_versions_are_not_exported(): void
    {
        [$user, $project] = $this->readyProject();
        $approved = $project->images()->firstOrFail();

        Image::factory()->for($project)->create([
            'title' => 'Rejected draft',
            'status' => ImageStatus::NeedsRework->value,
            'parent_image_id' => $approved->getKey(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $exportId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->json('data.id');

        $zip = $this->openStoredZip($exportId);
        $this->assertSame('PNG-BYTES', $zip->getFromName('launch-reel/images/image-01.png'));
        $manifest = json_decode((string) $zip->getFromName('launch-reel/metadata/project.json'), true);
        $this->assertSame('Hero image', $manifest['assets']['images'][0]['title']);
        $this->assertCount(1, $manifest['assets']['images']);
        $zip->close();
    }

    public function test_valid_project_can_finalize_and_then_export_a_real_zip(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk()
            ->assertJsonPath('data.project.status', ProjectStatus::Completed->value)
            ->assertJsonPath('data.readiness.can_export', true)
            ->assertJsonPath('data.readiness.workflow_state', 'ready_to_export');

        $this->assertDatabaseHas('projects', [
            'id' => $project->getKey(),
            'status' => ProjectStatus::Completed->value,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->assertJsonPath('data.status', ExportStatus::Completed->value)
            ->assertJsonPath('data.filename', 'launch-reel-export.zip');

        $payload = $response->json('data');
        $this->assertIsInt($payload['size']);
        $this->assertGreaterThan(0, $payload['size']);
        $this->assertStringNotContainsString('storage/app', $response->getContent() ?: '');
        $this->assertStringNotContainsString('hn9/exports', $response->getContent() ?: '');

        $export = Export::query()->where('uuid', $payload['id'])->firstOrFail();
        $this->assertSame('exports', $export->disk);
        $this->assertTrue(Storage::disk('exports')->exists($export->path));

        $zip = $this->openStoredZip($export->uuid);
        $this->assertNotFalse($zip->locateName('launch-reel/README.txt'));
        $this->assertNotFalse($zip->locateName('launch-reel/script/final-script.txt'));
        $this->assertNotFalse($zip->locateName('launch-reel/images/image-01.png'));
        $this->assertNotFalse($zip->locateName('launch-reel/videos/video-01.mp4'));
        $this->assertNotFalse($zip->locateName('launch-reel/metadata/project.json'));
        $this->assertStringContainsString('Hello from the approved script.', (string) $zip->getFromName('launch-reel/script/final-script.txt'));
        $this->assertSame('PNG-BYTES', $zip->getFromName('launch-reel/images/image-01.png'));
        $this->assertSame('MP4-BYTES', $zip->getFromName('launch-reel/videos/video-01.mp4'));
        $this->assertStringContainsString('Launch Reel', (string) $zip->getFromName('launch-reel/README.txt'));
        $zip->close();
    }

    public function test_draft_project_finalizes_through_active_then_completed(): void
    {
        [$user, $project] = $this->readyProject(ProjectStatus::Draft);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk()
            ->assertJsonPath('data.project.status', ProjectStatus::Completed->value);
    }

    public function test_invalid_project_cannot_finalize_or_export(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'name' => 'Empty Project',
            'status' => ProjectStatus::Active->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'project_not_ready_for_export')
            ->assertJsonPath('context.issues.0.code', 'script_not_approved');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'project_not_ready_for_export');

        $this->assertDatabaseCount('exports', 0);
    }

    public function test_export_before_finalize_is_blocked(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'project_not_finalized');

        $this->assertDatabaseCount('exports', 0);
    }

    public function test_archived_project_cannot_finalize_or_export(): void
    {
        [$user, $project] = $this->readyProject(ProjectStatus::Archived);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'export_project_archived');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'export_project_archived');
    }

    public function test_unauthenticated_finalization_export_and_download_are_rejected(): void
    {
        [, $project] = $this->readyProject();

        $this->getJson('/api/v1/projects/'.$project->uuid.'/final-assets')->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$project->uuid.'/finalize')->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$project->uuid.'/export')->assertUnauthorized();
        $this->getJson('/api/v1/projects/'.$project->uuid.'/exports')->assertUnauthorized();
        $this->getJson('/api/v1/exports')->assertUnauthorized();
        $this->postJson('/api/v1/exports', ['project_id' => $project->uuid])->assertUnauthorized();
        $this->get('/api/v1/projects/'.$project->uuid.'/exports/'.Str::uuid().'/download')->assertUnauthorized();
        $this->get('/api/v1/exports/'.Str::uuid().'/download')->assertUnauthorized();
    }

    public function test_another_user_cannot_finalize_export_or_download(): void
    {
        [$owner, $project] = $this->readyProject();
        $intruder = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $exportId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/exports/'.$exportId)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/exports/'.$exportId.'/download')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get('/api/v1/exports/'.$exportId.'/download')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/exports/'.$exportId)
            ->assertForbidden();
    }

    public function test_foreign_uuid_is_not_found(): void
    {
        [$user] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.Str::uuid().'/final-assets')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exports/'.Str::uuid())
            ->assertNotFound();
    }

    public function test_missing_file_after_finalize_does_not_create_a_successful_export(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $image = $project->images()->firstOrFail();
        Storage::disk('images')->delete($image->file?->path ?? '');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'project_not_ready_for_export')
            ->assertJsonPath('context.issues.0.code', 'image_file_missing');

        $this->assertDatabaseCount('exports', 0);
        $this->assertSame([], Storage::disk('exports')->allFiles());
    }

    public function test_unreadable_asset_fails_the_export_without_a_downloadable_package(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $image = $project->images()->with('file')->firstOrFail();
        Storage::disk('images')->delete($image->file->path);
        Storage::disk('images')->makeDirectory($image->file->path);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->assertJsonPath('data.status', ExportStatus::Failed->value)
            ->assertJsonPath('data.error', 'The export package could not be created.');

        $export = Export::query()->firstOrFail();
        $this->assertNull($export->path);
        $this->assertNull($export->completed_at);
        $this->assertSame([], Storage::disk('exports')->allFiles());
    }

    public function test_package_builder_failure_marks_export_failed(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $this->mock(ExportPackageBuilder::class, function ($mock): void {
            $mock->shouldReceive('build')->once()->andThrow(ExportException::packageFailed());
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->assertJsonPath('data.status', ExportStatus::Failed->value);

        $this->assertDatabaseMissing('exports', ['status' => ExportStatus::Completed->value]);
        $this->assertSame([], Storage::disk('exports')->allFiles());
    }

    public function test_in_progress_export_is_not_duplicated(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        Export::factory()->for($user)->for($project)->processing()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'export_in_progress');

        $this->assertDatabaseCount('exports', 1);
    }

    public function test_completed_export_with_the_same_assets_is_reused(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->json('data.id');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('exports', 1);
    }

    public function test_owner_can_download_the_zip_and_paths_stay_hidden(): void
    {
        [$user, $project] = $this->readyProject();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/finalize')
            ->assertOk();

        $exportId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/export')
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/exports/'.$exportId.'/download');

        $response->assertOk()
            ->assertHeader('content-type', 'application/zip')
            ->assertDownload('launch-reel-export.zip');

        $this->assertStringNotContainsString('storage/app', $response->headers->get('content-disposition') ?: '');

        $tmp = tempnam(sys_get_temp_dir(), 'hn9exp');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertNotFalse($zip->locateName('launch-reel/videos/video-01.mp4'));
        $zip->close();
        @unlink($tmp);

        $global = $this->actingAs($user, 'sanctum')
            ->get('/api/v1/exports/'.$exportId.'/download');
        $global->assertOk()->assertDownload('launch-reel-export.zip');
    }

    public function test_incomplete_export_cannot_be_downloaded(): void
    {
        [$user, $project] = $this->readyProject();

        $export = Export::factory()->for($user)->for($project)->create([
            'status' => ExportStatus::Queued->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/exports/'.$export->uuid.'/download')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'export_not_ready');
    }

    public function test_missing_zip_returns_a_structured_error(): void
    {
        [$user, $project] = $this->readyProject();

        $export = Export::factory()->for($user)->for($project)->completed()->create([
            'path' => 'gone/missing.zip',
            'filename' => 'launch-reel-export.zip',
        ]);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/exports/'.$export->uuid.'/download')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'export_package_missing');
    }

    public function test_export_from_another_project_is_not_found(): void
    {
        [$user, $project] = $this->readyProject();
        $other = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $export = Export::factory()->for($user)->for($project)->completed()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$other->uuid.'/exports/'.$export->uuid)
            ->assertNotFound();
    }

    public function test_global_csv_export_still_creates_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/exports', ['format' => 'csv'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'export_invalid_request');

        $this->assertDatabaseCount('exports', 0);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function readyProject(ProjectStatus $status = ProjectStatus::Active): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'name' => 'Launch Reel',
            'status' => $status->value,
        ]);

        Script::factory()->for($project)->approved()->create([
            'title' => 'Launch script',
            'body' => 'Hello from the approved script.',
        ]);

        $image = Image::factory()->for($project)->create([
            'title' => 'Hero image',
            'status' => ImageStatus::Approved->value,
        ]);
        $this->storeImage($image, 'PNG-BYTES');

        $video = Video::factory()->for($project)->create([
            'title' => 'Hero video',
            'status' => VideoStatus::Approved->value,
        ]);
        $this->storeVideo($video, 'MP4-BYTES');

        return [$user, $project->fresh() ?? $project];
    }

    private function storeImage(Image $image, string $contents): MediaFile
    {
        $path = 'final/'.$image->uuid.'.png';
        Storage::disk('images')->put($path, $contents);

        return MediaFile::factory()->create([
            'mediable_type' => Image::class,
            'mediable_id' => $image->getKey(),
            'disk' => 'images',
            'path' => $path,
            'original_name' => 'image.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => strlen($contents),
            'collection' => 'images',
        ]);
    }

    private function storeVideo(Video $video, string $contents): MediaFile
    {
        $path = 'final/'.$video->uuid.'.mp4';
        Storage::disk('videos')->put($path, $contents);

        return MediaFile::factory()->create([
            'mediable_type' => Video::class,
            'mediable_id' => $video->getKey(),
            'disk' => 'videos',
            'path' => $path,
            'original_name' => 'video.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'size' => strlen($contents),
            'collection' => 'videos',
        ]);
    }

    private function openStoredZip(string $exportUuid): ZipArchive
    {
        $export = Export::query()->where('uuid', $exportUuid)->firstOrFail();
        $this->assertNotNull($export->path);
        $this->assertTrue(Storage::disk('exports')->exists($export->path));

        $zip = new ZipArchive;
        $opened = $zip->open(Storage::disk('exports')->path($export->path));
        $this->assertTrue($opened === true, 'The stored export is not a valid ZIP.');

        return $zip;
    }
}
