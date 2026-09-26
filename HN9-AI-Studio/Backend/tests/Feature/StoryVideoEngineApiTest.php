<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoryVideoEngineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_video_engine_requests_are_rejected(): void
    {
        $uuid = (string) Str::uuid();

        $this->getJson('/api/v1/story/video/capabilities')->assertUnauthorized();
        $this->getJson('/api/v1/story/video/providers')->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/video/validate", [])->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/video/jobs", [])->assertUnauthorized();
    }

    public function test_capability_catalog_exposes_all_six_modes_with_metadata(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/video/capabilities')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(6, $data);
        $this->assertSame(StoryVideoCapability::values(), array_column($data, 'capability'));

        $text = collect($data)->firstWhere('capability', 'text_to_video');
        $this->assertTrue($text['available']);
        $this->assertNotEmpty($text['supported_durations']);
        $this->assertNotEmpty($text['supported_aspect_ratios']);
        $this->assertNotEmpty($text['supported_input_types']);
        $this->assertArrayHasKey('audio_supported', $text);
        $this->assertArrayHasKey('polling_supported', $text);
        $this->assertArrayHasKey('webhook_supported', $text);
        $this->assertArrayHasKey('download_supported', $text);
        $this->assertStringNotContainsString('seedance', strtolower(json_encode($data)));
    }

    public function test_provider_catalog_is_safe_and_priority_ordered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/video/providers')
            ->assertOk();

        $providers = $response->json('data');
        $this->assertCount(3, $providers);
        $this->assertSame('catalog.alpha', $providers[0]['key']);
        $this->assertSame(100, $providers[0]['priority']);
        $this->assertGreaterThanOrEqual($providers[2]['priority'], $providers[1]['priority']);
        $encoded = strtolower(json_encode($providers));
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('authorization', $encoded);
        $this->assertStringNotContainsString('seedance', $encoded);
    }

    public function test_owner_can_validate_scene_compatibility_and_prepare_idempotent_job(): void
    {
        Http::fake();
        [$user, $project] = $this->ownerProject();

        $reelId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", ['title' => 'Engine Reel'])
            ->json('data.id');
        $sceneId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes", [
                'title' => 'Scene 1',
                'duration_seconds' => 30,
                'story' => 'Aarav enters',
                'visual_prompt' => 'Cave mouth',
            ])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/validate", [
                'capability' => 'text_to_video',
                'reel_id' => $reelId,
                'scene_id' => $sceneId,
                'duration_seconds' => 30,
                'aspect_ratio' => '9:16',
                'audio_requested' => true,
                'prompt' => 'Aarav enters the cave',
            ])
            ->assertOk()
            ->assertJsonPath('data.compatible', true)
            ->assertJsonPath('data.routing.provider', 'catalog.alpha');

        $key = 'idem-'.Str::uuid();
        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/jobs", [
                'capability' => 'text_to_video',
                'reel_id' => $reelId,
                'scene_id' => $sceneId,
                'duration_seconds' => 30,
                'aspect_ratio' => '9:16',
                'prompt' => 'Aarav enters the cave',
                'idempotency_key' => $key,
            ])
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.job.status', StoryVideoJobStatus::Queued->value)
            ->assertJsonPath('data.job.provider', 'catalog.alpha');

        $jobId = $first->json('data.job.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/jobs", [
                'capability' => 'text_to_video',
                'duration_seconds' => 30,
                'aspect_ratio' => '9:16',
                'prompt' => 'duplicate',
                'idempotency_key' => $key,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.job.id', $jobId);

        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_incompatible_request_is_rejected_without_job(): void
    {
        [$user, $project] = $this->ownerProject();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/validate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 11,
                'aspect_ratio' => '9:16',
            ])
            ->assertOk()
            ->assertJsonPath('data.compatible', false);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/jobs", [
                'capability' => 'text_to_video',
                'duration_seconds' => 11,
                'aspect_ratio' => '9:16',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VIDEO_CAPABILITY_NOT_AVAILABLE');

        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_non_owner_cannot_validate_or_prepare_jobs(): void
    {
        [$owner, $project] = $this->ownerProject();
        $intruder = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/validate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 30,
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/jobs", [
                'capability' => 'text_to_video',
                'duration_seconds' => 30,
                'aspect_ratio' => '9:16',
            ])
            ->assertForbidden();
    }

    public function test_invalid_project_uuid_is_safe_404(): void
    {
        $user = User::factory()->create();
        $missing = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$missing}/video/validate", [
                'capability' => 'text_to_video',
            ])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownerProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        return [$user, $project];
    }
}
