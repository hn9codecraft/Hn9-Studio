<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProviderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_show_providers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProvider::factory()->create(['status' => Status::Active->value]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers/'.$provider->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.slug', $provider->slug);
    }

    public function test_admin_can_update_enable_disable_and_test_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProvider::factory()->create(['status' => Status::Active->value]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/providers/'.$provider->uuid, ['name' => 'Updated Provider', 'priority' => 99])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Provider');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/disable')
            ->assertStatus(200)
            ->assertJsonPath('data.status', Status::Inactive->value);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/enable')
            ->assertStatus(200)
            ->assertJsonPath('data.status', Status::Active->value);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/test')
            ->assertStatus(501)
            ->assertJsonPath('error_code', 'not_implemented');

        $this->assertDatabaseHas('ai_providers', [
            'id' => $provider->id,
            'status' => Status::Active->value,
        ]);
        $provider->refresh();
        $this->assertArrayNotHasKey('last_tested_at', (array) $provider->metadata);
    }

    public function test_non_admin_cannot_manage_providers(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $provider = AiProvider::factory()->create();

        $this->actingAs($editor, 'sanctum')
            ->getJson('/api/v1/providers')
            ->assertStatus(403);

        $this->actingAs($editor, 'sanctum')
            ->patchJson('/api/v1/providers/'.$provider->uuid, ['name' => 'Nope'])
            ->assertStatus(403);

        $this->actingAs($editor, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/disable')
            ->assertStatus(403);

        $this->actingAs($editor, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/enable')
            ->assertStatus(403);
    }

    public function test_validation_errors_are_returned_for_provider_updates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProvider::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/providers/'.$provider->uuid, ['priority' => -1])
            ->assertStatus(422);
    }

    public function test_provider_settings_can_be_listed_and_updated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = AiProvider::factory()->create();
        $setting = ProviderSetting::factory()->for($provider, 'provider')->create(['is_secret' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/provider-settings')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/provider-settings/'.$setting->uuid, ['value' => 'new-secret', 'environment' => 'staging', 'is_secret' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.environment', 'staging')
            ->assertJsonPath('data.is_secret', true);
    }

    public function test_unauthenticated_users_cannot_access_provider_endpoints(): void
    {
        $provider = AiProvider::factory()->create();

        $this->getJson('/api/v1/providers')
            ->assertStatus(401);

        $this->getJson('/api/v1/providers/'.$provider->uuid)
            ->assertStatus(401);

        $this->getJson('/api/v1/provider-settings')
            ->assertStatus(401);
    }

    public function test_members_cannot_read_or_update_provider_settings(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        $provider = AiProvider::factory()->create();
        $setting = ProviderSetting::factory()->for($provider, 'provider')->create();

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/provider-settings')
            ->assertStatus(403);

        $this->actingAs($member, 'sanctum')
            ->patchJson('/api/v1/provider-settings/'.$setting->uuid, ['value' => 'stolen'])
            ->assertStatus(403);
    }

    public function test_secret_provider_settings_are_masked_and_never_echo_plaintext(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = AiProvider::factory()->create();
        $secret = 'sk-live-do-not-leak-'.fake()->uuid();
        $setting = ProviderSetting::factory()->for($provider, 'provider')->secret()->create([
            'value' => $secret,
        ]);

        $list = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $provider->uuid)
            ->assertJsonPath('data.0.settings.0.id', $setting->uuid)
            ->assertJsonPath('data.0.settings.0.is_secret', true)
            ->assertJsonPath('data.0.settings.0.value', '********');

        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $list->getContent());

        $updated = $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/provider-settings/'.$setting->uuid, [
                'value' => 'replacement-secret-value',
                'is_secret' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.value', '********')
            ->assertJsonPath('data.is_secret', true);

        $this->assertStringNotContainsString('replacement-secret-value', $updated->getContent());
        $this->assertSame('replacement-secret-value', $setting->fresh()->value);

        $reload = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers/'.$provider->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.settings.0.value', '********');

        $this->assertStringNotContainsString('replacement-secret-value', $reload->getContent());
    }

    public function test_enable_disable_and_name_updates_persist_on_reload(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = AiProvider::factory()->create([
            'name' => 'Original Registry Name',
            'status' => Status::Active->value,
            'priority' => 10,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/providers/'.$provider->uuid, [
                'name' => 'Persisted Registry Name',
                'priority' => 42,
            ])
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/disable')
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers/'.$provider->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Persisted Registry Name')
            ->assertJsonPath('data.priority', 42)
            ->assertJsonPath('data.status', Status::Inactive->value);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/providers/'.$provider->uuid.'/enable')
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/providers/'.$provider->uuid)
            ->assertJsonPath('data.status', Status::Active->value);
    }
}
