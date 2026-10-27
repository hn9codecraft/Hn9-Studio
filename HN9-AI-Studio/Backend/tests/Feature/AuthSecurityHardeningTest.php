<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * M12 security patch: login and password throttling, generation limits,
 * disabled accounts, token expiry and token revocation. No provider is called.
 */
class AuthSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function login(mixed $email, mixed $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** Make the next request authenticate from its Authorization header. */
    private function freshRequest(): void
    {
        Auth::forgetGuards();
    }

    public function test_login_is_throttled_after_five_attempts_for_the_same_email_and_ip(): void
    {
        User::factory()->create(['email' => 'studio@example.com', 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('studio@example.com', 'wrong-password')->assertUnauthorized();
        }

        // Email case does not create a fresh bucket, and a correct password is
        // still refused while the limit is active.
        $response = $this->login('Studio@Example.com', 'password');
        $response->assertStatus(429)
            ->assertJsonPath('error_code', 'auth.throttled')
            ->assertHeader('Retry-After');
        $this->assertStringNotContainsString('wrong-password', (string) $response->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Another account from the same address can still try.
        $this->login('other@example.com', 'password')->assertUnauthorized();

        // The window resets.
        $this->travel(61)->seconds();
        $this->login('studio@example.com', 'password')->assertOk();
    }

    public function test_login_is_capped_per_ip_across_different_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->login("user{$i}@example.com", 'password')->assertUnauthorized();
        }

        $this->login('user-last@example.com', 'password')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'auth.throttled');
    }

    public function test_login_rejects_non_string_credentials(): void
    {
        User::factory()->create(['email' => 'studio@example.com', 'password' => 'password']);

        $this->login(['studio@example.com'], 'password')->assertStatus(422);
        $this->login('studio@example.com', ['password'])->assertStatus(422);
        $this->login('studio@example.com', true)->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_and_suspended_users_cannot_log_in_or_receive_a_token(): void
    {
        foreach (['inactive', 'suspended'] as $status) {
            User::factory()->create([
                'email' => "{$status}@example.com",
                'password' => 'password',
                'status' => $status,
            ]);

            $this->login("{$status}@example.com", 'password')
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Invalid credentials', 'error_code' => 'auth.invalid']);
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_active_user_login_issues_a_working_token(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.com',
            'password' => 'password',
            'status' => 'active',
        ]);

        $token = $this->login('active@example.com', 'password')->assertOk()->json('data.token');

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->withToken($token)->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'active@example.com');
    }

    public function test_existing_token_stops_working_when_the_user_is_disabled(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk();

        // Changed outside the admin API, so no revocation ran: the token
        // check itself must refuse the disabled owner.
        User::whereKey($user->id)->update(['status' => 'inactive']);
        $this->freshRequest();

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_admin_disabling_a_user_revokes_their_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $member->createToken('api-token');
        $member->createToken('api-token');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/users/'.$member->uuid, ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame(0, $member->tokens()->count());
    }

    public function test_admin_editing_an_active_user_keeps_their_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $member->createToken('api-token');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/users/'.$member->uuid, ['name' => 'Renamed', 'status' => 'active'])
            ->assertOk();

        $this->assertSame(1, $member->tokens()->count());
    }

    public function test_tokens_expire_after_the_configured_lifetime(): void
    {
        $this->assertIsInt(config('sanctum.expiration'));
        $this->assertGreaterThan(0, config('sanctum.expiration'));

        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->travel(59)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk();

        $this->travel(2)->minutes();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_token_with_a_past_expiry_date_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_revoked_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk();

        $user->tokens()->delete();
        $this->freshRequest();

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_password_change_revokes_other_tokens_but_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $current = $user->createToken('api-token');
        $other = $user->createToken('api-token')->plainTextToken;

        $this->withToken($current->plainTextToken)
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'new_password' => 'new-password',
                'new_password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Password updated');

        $this->assertSame([$current->accessToken->id], $user->tokens()->pluck('id')->all());

        $this->freshRequest();
        $this->withToken($other)->getJson('/api/v1/auth/user')->assertUnauthorized();

        $this->freshRequest();
        $this->withToken($current->plainTextToken)->getJson('/api/v1/auth/user')->assertOk();
    }

    public function test_failed_password_change_does_not_revoke_tokens(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $current = $user->createToken('api-token')->plainTextToken;
        $user->createToken('api-token');

        $this->withToken($current)
            ->patchJson('/api/v1/auth/password', [
                'current_password' => 'wrong-password',
                'new_password' => 'new-password',
                'new_password_confirmation' => 'new-password',
            ])
            ->assertStatus(422);

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_password_change_is_throttled_per_user(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $payload = [
            'current_password' => 'wrong-password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'sanctum')->patchJson('/api/v1/auth/password', $payload)->assertStatus(422);
        }

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/auth/password', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'auth.throttled');
    }

    public function test_expensive_generation_routes_use_the_generation_limiter(): void
    {
        $names = [
            'projects.scripts.generate', 'projects.scripts.regenerate',
            'projects.images.generate', 'projects.images.regenerate',
            'projects.videos.generate', 'projects.videos.regenerate',
            'brand-brain.insights', 'projects.generate', 'generated-content.regenerate',
            'providers.test',
            'story.projects.characters.references.generate', 'story.projects.style.references.generate',
            'story.projects.plans.generate', 'story.projects.plans.regenerate',
            'story.projects.production-plans.units.generate',
            'story.projects.production-plans.scenes.assemble',
            'story.projects.reels.scenes.regenerate',
            'story.projects.reels.scenes.versions.edit', 'story.projects.reels.scenes.versions.extend',
            'story.projects.reels.scenes.audio.store',
            'story.projects.reels.renders.store',
            'story.projects.video.generate',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName('api.v1.'.$name);
            $this->assertNotNull($route, $name);
            $this->assertContains('throttle:generation', $route->gatherMiddleware(), $name);
        }

        $this->assertContains('throttle:login', Route::getRoutes()->getByName('api.v1.auth.login')->gatherMiddleware());
        $this->assertContains('throttle:password', Route::getRoutes()->getByName('api.v1.auth.password')->gatherMiddleware());

        // Ordinary reads are not throttled indiscriminately.
        foreach (['auth.user', 'story.projects.show', 'projects.index'] as $name) {
            $middleware = Route::getRoutes()->getByName('api.v1.'.$name)->gatherMiddleware();
            $this->assertEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'throttle')), $name);
        }
    }

    public function test_generation_limiter_allows_thirty_requests_per_user_per_minute(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'throttle:generation'])
            ->post('/api/v1/__m12-generation-probe', fn () => response()->json(['data' => 'ok']));

        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/__m12-generation-probe')->assertOk();
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/__m12-generation-probe')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'rate_limited')
            ->assertHeader('Retry-After');

        // Limits are per user, not global.
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/__m12-generation-probe')->assertOk();
    }

    public function test_deleting_a_user_revokes_their_existing_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $token = $member->createToken('api-token')->plainTextToken;
        $member->createToken('api-token');

        $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk();

        $this->freshRequest();
        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/users/'.$member->uuid)
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $member->getMorphClass(),
            'tokenable_id' => $member->id,
        ]);

        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_users_with_an_empty_or_unknown_status_cannot_log_in_or_use_a_token(): void
    {
        foreach (['', 'pending'] as $index => $status) {
            $user = User::factory()->create([
                'email' => "status{$index}@example.com",
                'password' => 'password',
            ]);
            $token = $user->createToken('api-token')->plainTextToken;

            // Written directly, as legacy or hand-edited rows would be.
            User::whereKey($user->id)->update(['status' => $status]);
            $this->freshRequest();

            $this->login("status{$index}@example.com", 'password')
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Invalid credentials', 'error_code' => 'auth.invalid']);

            $this->freshRequest();
            $this->withToken($token)->getJson('/api/v1/auth/user')->assertUnauthorized();

            $this->assertSame(1, $user->tokens()->count(), "login must not issue a token for status '{$status}'");
        }
    }
}
