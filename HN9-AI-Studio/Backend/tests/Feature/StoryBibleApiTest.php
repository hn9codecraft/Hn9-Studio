<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoryBibleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_bible_requests_are_rejected(): void
    {
        $uuid = (string) Str::uuid();

        $this->getJson("/api/v1/story/projects/{$uuid}/bible")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/bible")->assertUnauthorized();
        $this->patchJson("/api/v1/story/projects/{$uuid}/bible", [])->assertUnauthorized();
    }

    public function test_owner_can_initialize_and_read_an_empty_bible(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.concept', null)
            ->assertJsonPath('data.project.id', $project->uuid)
            ->assertJsonPath('data.audio_defaults.voice_enabled', null);

        $this->assertTrue(Str::isUuid($response->json('data.id')));
        $this->assertTrue(Str::isUuid($response->json('data.workspace.id')));
        $this->assertSame(1, StoryWorkspace::query()->count());
        $this->assertSame(1, StoryBible::query()->count());
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $response->getContent() ?: '');
        Http::assertNothingSent();
    }

    public function test_post_reuses_the_same_bible_for_a_workspace(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertCreated();

        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, StoryBible::query()->count());
        $this->assertSame(1, StoryWorkspace::query()->count());
    }

    public function test_owner_can_update_and_reread_persisted_values(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $payload = [
            'concept' => '  A founder builds a quiet product.  ',
            'genre' => 'Drama',
            'audience' => 'Founders',
            'language' => 'en',
            'tone' => 'Calm',
            'world' => 'Contemporary studio',
            'location' => 'Ahmedabad',
            'time_period' => 'Present day',
            'narrative_style' => 'Documentary',
            'video_style' => 'Handheld close-ups',
            'aspect_ratio' => '9:16',
            'default_duration' => 8,
            'audio_defaults' => [
                'voice_enabled' => true,
                'music_enabled' => false,
                'sfx_enabled' => true,
                'voice_style' => 'Warm narration',
                'music_mood' => '  ',
            ],
        ];

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", $payload)
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.concept', 'A founder builds a quiet product.')
            ->assertJsonPath('data.genre', 'Drama')
            ->assertJsonPath('data.aspect_ratio', '9:16')
            ->assertJsonPath('data.default_duration', 8)
            ->assertJsonPath('data.audio_defaults.voice_enabled', true)
            ->assertJsonPath('data.audio_defaults.music_enabled', false)
            ->assertJsonPath('data.audio_defaults.music_mood', null);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertOk()
            ->assertJsonPath('data.location', 'Ahmedabad')
            ->assertJsonPath('data.video_style', 'Handheld close-ups');
    }

    public function test_projects_keep_isolated_bibles(): void
    {
        $user = User::factory()->create();
        $first = Project::factory()->for($user)->create();
        $second = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$first->uuid}/bible", ['genre' => 'Thriller'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$second->uuid}/bible", ['genre' => 'Comedy'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$first->uuid}/bible")
            ->assertOk()
            ->assertJsonPath('data.genre', 'Thriller');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$second->uuid}/bible")
            ->assertOk()
            ->assertJsonPath('data.genre', 'Comedy');

        $this->assertSame(2, StoryBible::query()->count());
    }

    public function test_non_owner_cannot_read_or_update_another_users_bible(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", ['genre' => 'Mystery'])
            ->assertOk();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", ['genre' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame('Mystery', StoryBible::query()->first()?->genre);
    }

    public function test_unknown_and_numeric_project_ids_are_not_found(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.Str::uuid().'/bible')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/story/projects/'.$project->getKey().'/bible', ['genre' => 'Drama'])
            ->assertNotFound();
    }

    public function test_validation_rejects_malformed_bible_values(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", [
                'aspect_ratio' => '4:3',
                'default_duration' => -1,
                'audio_defaults' => ['voice_enabled' => 'loud'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['aspect_ratio', 'default_duration', 'audio_defaults.voice_enabled']);
    }

    public function test_m11_0_story_routes_still_work(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/story')->assertOk()->assertJsonPath('data.module', 'project_story');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/story/projects')->assertOk();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->uuid)
            ->assertOk()
            ->assertJsonPath('data.project.id', $project->uuid);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/story/capabilities')->assertOk();
    }
}
