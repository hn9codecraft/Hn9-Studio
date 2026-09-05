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
}
