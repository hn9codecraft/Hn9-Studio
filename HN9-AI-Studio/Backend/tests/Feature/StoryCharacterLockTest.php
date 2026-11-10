<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterLock;
use App\Story\Models\StoryCharacterLockEvent;
use App\Story\Models\StoryCharacterReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class StoryCharacterLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_lock_requires_an_approved_reference_and_does_not_approve_one(): void
    {
        $user = User::factory()->create();
        $character = $this->character($user);
        $draft = $this->reference($character, StoryCharacterReferenceStatus::Draft);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->lockUrl($character), ['reference_uuid' => $draft->uuid])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_character_lock_needs_reference');
        $this->assertSame(0, StoryCharacterLock::query()->count());
        $this->assertSame(StoryCharacterReferenceStatus::Draft->value, $draft->fresh()->status);

        $approved = $this->reference($character, StoryCharacterReferenceStatus::Approved);
        $character->forceFill(['approved_reference_id' => $approved->id])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->lockUrl($character))
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.lock.reference_uuid', $approved->uuid);

        $this->assertSame(1, StoryCharacterLock::query()->count());
        $this->assertSame(1, StoryCharacterLockEvent::query()->where('action', 'lock')->count());
        $this->assertSame(StoryCharacterReferenceStatus::Approved->value, $approved->fresh()->status);
        $log = ActivityLog::query()->where('action', 'story.character.locked')->first();
        $this->assertNotNull($log);
        $encoded = json_encode($log->properties);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('blue coat', $encoded);
        $this->assertSame($approved->uuid, $log->properties['reference_uuid']);
    }

    public function test_lock_is_idempotent_relock_records_history_and_unlock_twice_is_quiet(): void
    {
        $user = User::factory()->create();
        $character = $this->character($user, ['appearance' => 'blue coat']);
        $first = $this->approve($character);
        $url = $this->lockUrl($character);

        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk()->assertJsonPath('data.changed', true);
        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk()->assertJsonPath('data.changed', false);
        $this->assertSame(1, StoryCharacterLockEvent::query()->count());

        $second = $this->approve($character);
        $this->actingAs($user, 'sanctum')
            ->postJson($url, ['reference_uuid' => $second->uuid])
            ->assertOk()
            ->assertJsonPath('data.changed', true);
        $this->assertSame(1, StoryCharacterLockEvent::query()->where('action', 'relock')->count());

        $this->actingAs($user, 'sanctum')->deleteJson($url)->assertOk()->assertJsonPath('data.changed', true);
        $this->assertSame(0, StoryCharacterLock::query()->count());
        $this->assertSame(1, StoryCharacterLockEvent::query()->where('action', 'unlock')->count());
        $this->actingAs($user, 'sanctum')->deleteJson($url)->assertOk()->assertJsonPath('data.changed', false);
    }

    public function test_locked_look_fields_are_frozen_until_unlock(): void
    {
        $user = User::factory()->create();
        $character = $this->character($user);
        $this->approve($character);
        $this->actingAs($user, 'sanctum')->postJson($this->lockUrl($character))->assertOk();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$character->workspace->project->uuid}/characters/{$character->uuid}", [
                'name' => 'Someone else',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_character_locked');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$character->workspace->project->uuid}/characters/{$character->uuid}", [
                'personality' => 'Quiet',
            ])
            ->assertOk()
            ->assertJsonPath('data.personality', 'Quiet');

        $reference = StoryCharacterReference::query()->findOrFail($character->fresh()->approved_reference_id);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$character->workspace->project->uuid}/characters/{$character->uuid}/references/{$reference->uuid}/archive")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_character_reference_locked');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/story/projects/{$character->workspace->project->uuid}/characters/{$character->uuid}")
            ->assertStatus(409);

        $this->actingAs($user, 'sanctum')->deleteJson($this->lockUrl($character))->assertOk();
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$character->workspace->project->uuid}/characters/{$character->uuid}", [
                'name' => 'Maya',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Maya');
    }

    public function test_lock_routes_require_auth_and_are_throttled(): void
    {
        $user = User::factory()->create();
        $character = $this->character($user);
        $this->postJson($this->lockUrl($character))->assertUnauthorized();

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson($this->lockUrl($character))->assertForbidden();

        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('api.v1.story.projects.characters.lock')->gatherMiddleware());
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('api.v1.story.projects.character-locks.index')->gatherMiddleware());
    }

    public function test_a_reference_from_another_character_is_not_found(): void
    {
        $user = User::factory()->create();
        $character = $this->character($user);
        $other = $this->character($user, ['name' => 'Other']);
        $reference = $this->approve($other);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->lockUrl($character), ['reference_uuid' => $reference->uuid])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function character(User $user, array $extra = []): StoryCharacter
    {
        $project = \App\Models\Project::factory()->for($user)->create();
        $workspace = app(\App\Story\Contracts\StoryWorkspaceServiceInterface::class)->workspaceForProject($project);

        return StoryCharacter::factory()->create([
            'story_workspace_id' => $workspace->id,
            'name' => 'Maya',
            'appearance' => 'blue coat',
            'status' => 'draft',
        ] + $extra);
    }

    private function reference(StoryCharacter $character, StoryCharacterReferenceStatus $status): StoryCharacterReference
    {
        return StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'status' => $status->value,
            'version' => StoryCharacterReference::query()->where('story_character_id', $character->id)->count() + 1,
        ]);
    }

    private function approve(StoryCharacter $character): StoryCharacterReference
    {
        $reference = $this->reference($character, StoryCharacterReferenceStatus::Approved);
        $character->forceFill(['approved_reference_id' => $reference->id])->save();

        return $reference;
    }

    private function lockUrl(StoryCharacter $character): string
    {
        $character->loadMissing('workspace.project');

        return "/api/v1/story/projects/{$character->workspace->project->uuid}/characters/{$character->uuid}/lock";
    }
}
