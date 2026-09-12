<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_routes_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/users/'.$user->uuid)->assertUnauthorized();
        $this->patchJson('/api/v1/users/'.$user->uuid, ['name' => 'Nope'])->assertUnauthorized();
        $this->deleteJson('/api/v1/users/'.$user->uuid)->assertUnauthorized();
    }

    public function test_member_cannot_list_users(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        User::factory()->create(['role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertForbidden();
    }

    public function test_member_cannot_view_update_or_delete_another_user(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $intruder = User::factory()->create(['role' => 'member']);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/users/'.$owner->uuid)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/v1/users/'.$owner->uuid, ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson('/api/v1/users/'.$owner->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'name' => 'Owner',
            'deleted_at' => null,
        ]);
    }

    public function test_integer_user_id_does_not_resolve(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $intruder = User::factory()->create(['role' => 'member']);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/users/'.$owner->getKey())
            ->assertNotFound();
    }

    public function test_login_exposes_uuid_not_integer_id(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'password',
        ]);

        $publicId = $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->json('data.user.id');

        $this->assertSame($user->uuid, $publicId);
        $this->assertNotSame((string) $user->getKey(), (string) $publicId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $publicId,
        );
    }

    public function test_admin_can_list_and_show_users_by_uuid(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['name' => 'Studio Member']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users/'.$member->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $member->uuid)
            ->assertJsonPath('data.name', 'Studio Member');
    }

    public function test_member_cannot_escalate_role_through_the_users_api(): void
    {
        $member = User::factory()->create(['role' => 'member', 'name' => 'Member']);

        $this->actingAs($member, 'sanctum')
            ->patchJson('/api/v1/users/'.$member->uuid, [
                'role' => 'admin',
                'permissions' => ['project.create'],
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'role' => 'member',
            'name' => 'Member',
        ]);
    }
}
