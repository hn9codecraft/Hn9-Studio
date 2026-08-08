<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the authorization boundary on the audit trail.
 *
 * The activity log applies no ownership scope, so before the code-freeze audit
 * any authenticated member could read every user's log rows including their IP
 * addresses and before/after payloads. These tests exist to keep that closed.
 */
final class SystemEndpointAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_logs_are_forbidden_to_a_non_admin(): void
    {
        $member = User::factory()->create(['role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/system/activity-logs')
            ->assertStatus(403);
    }

    public function test_activity_logs_do_not_leak_another_users_audit_trail(): void
    {
        $victim = User::factory()->create();
        $intruder = User::factory()->create(['role' => 'member']);

        ActivityLog::factory()->create([
            'user_id' => $victim->getKey(),
            'action' => 'project.created',
            'ip_address' => '203.0.113.7',
        ]);

        $this->assertDatabaseCount('activity_logs', 1);

        $response = $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/system/activity-logs')
            ->assertStatus(403);

        $body = $response->getContent() ?: '';

        $this->assertStringNotContainsString('project.created', $body);
        $this->assertStringNotContainsString('203.0.113.7', $body);
    }

    public function test_an_admin_may_read_the_activity_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        ActivityLog::factory()->create(['action' => 'project.created']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/system/activity-logs')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['data', 'total']]);
    }

    public function test_activity_logs_require_authentication(): void
    {
        ActivityLog::query()->delete();

        $this->getJson('/api/v1/system/activity-logs')->assertStatus(401);
    }
}
