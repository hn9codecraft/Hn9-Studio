<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_public_and_returns_a_valid_sanctum_token(): void
    {
        $user = User::factory()->create([
            'email' => 'studio@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'studio@example.com',
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email);

        $token = $response->json('data.token');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertMatchesRegularExpression('/^\d+\|.+$/', $token);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => $user->getMorphClass(),
            'name' => 'api-token',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'studio@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'studio@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'auth.invalid');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'auth.invalid');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_user_route_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_authenticated_user_route_succeeds_with_a_bearer_token(): void
    {
        $user = User::factory()->create([
            'email' => 'studio@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'studio@example.com',
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.name', $user->name);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'studio@example.com',
            'password' => 'password',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'studio@example.com',
            'password' => 'password',
        ])->json('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.message', 'Logged out');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));

        // PHPUnit reuses one application instance per test. Forget the resolved
        // guard so the next request authenticates from the Authorization header
        // like a real HTTP client, instead of the in-memory user from logout.
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/user')
            ->assertUnauthorized();
    }

    public function test_profile_and_password_routes_require_authentication(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['name' => 'New Name'])
            ->assertUnauthorized();

        $this->patchJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ])->assertUnauthorized();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_health_endpoint_remains_public(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_authenticated_user_can_update_profile_and_reload_it(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'locale' => 'en',
            'timezone' => 'UTC',
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'Studio Operator',
                'locale' => 'en-GB',
                'timezone' => 'Europe/London',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Studio Operator')
            ->assertJsonPath('data.locale', 'en-GB')
            ->assertJsonPath('data.timezone', 'Europe/London')
            ->assertJsonMissingPath('data.password');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.name', 'Studio Operator')
            ->assertJsonPath('data.locale', 'en-GB')
            ->assertJsonPath('data.timezone', 'Europe/London')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Studio Operator',
            'locale' => 'en-GB',
            'timezone' => 'Europe/London',
        ]);
    }

    public function test_profile_validation_errors_are_returned(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/profile', ['name' => str_repeat('n', 200)])
            ->assertStatus(422);
    }

    public function test_password_update_persists_and_rejects_the_old_password(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'wrong-password',
                'new_password' => 'new-password',
                'new_password_confirmation' => 'new-password',
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'new_password' => 'short',
                'new_password_confirmation' => 'short',
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'new_password' => 'new-password',
                'new_password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Password updated')
            ->assertJsonMissingPath('data.password');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@example.com',
            'password' => 'password',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@example.com',
            'password' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'operator@example.com');
    }

    public function test_application_settings_endpoints_do_not_persist_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/settings', ['theme' => 'dark'])
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
