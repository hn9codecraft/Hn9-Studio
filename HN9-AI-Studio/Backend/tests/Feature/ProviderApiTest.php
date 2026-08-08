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
            ->assertStatus(200)
            ->assertJsonPath('data.status', Status::Active->value);
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
    }
}
