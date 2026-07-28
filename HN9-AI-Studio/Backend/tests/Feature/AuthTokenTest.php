<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_route_requires_a_token(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_user_can_be_resolved_with_a_sanctum_token(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJson(['id' => $user->id, 'email' => $user->email]);
    }

    public function test_user_can_issue_a_personal_access_token(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test-token');

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test-token',
        ]);
    }
}
