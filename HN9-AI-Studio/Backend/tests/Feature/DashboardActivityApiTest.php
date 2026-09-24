<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard/activity')->assertUnauthorized();
    }

    public function test_empty_owner_receives_an_empty_paginated_feed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/activity')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.perPage', 20);
    }

    public function test_owner_sees_paginated_studio_history_beyond_the_summary_cap(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        foreach (range(1, 25) as $index) {
            ActivityLog::factory()->create([
                'user_id' => $user->id,
                'subject_type' => $project->getMorphClass(),
                'subject_id' => $project->id,
                'action' => 'project.updated',
                'description' => "Update {$index}",
                'created_at' => now()->subSeconds(25 - $index),
            ]);
        }

        $first = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/activity?perPage=20&page=1')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.perPage', 20)
            ->assertJsonPath('meta.lastPage', 2);

        $this->assertSame('Update 25', $first->json('data.0.description'));
        $this->assertTrue(Str::isUuid($first->json('data.0.id')));
        $this->assertTrue(Str::isUuid($first->json('data.0.project.id')));
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $first->getContent() ?: '');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/activity?perPage=20&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('data.4.description', 'Update 1');
    }

    public function test_user_cannot_see_another_users_activity(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $owned = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $foreign = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);

        ActivityLog::factory()->create([
            'user_id' => $owner->id,
            'subject_type' => $owned->getMorphClass(),
            'subject_id' => $owned->id,
            'action' => 'project.created',
            'description' => 'Owner project created',
        ]);
        ActivityLog::factory()->create([
            'user_id' => $intruder->id,
            'subject_type' => $foreign->getMorphClass(),
            'subject_id' => $foreign->id,
            'action' => 'project.created',
            'description' => 'Intruder project created',
            'ip_address' => '203.0.113.88',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/activity')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Owner project created');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('Intruder', $body);
        $this->assertStringNotContainsString($foreign->uuid, $body);
        $this->assertStringNotContainsString('203.0.113.88', $body);
        $this->assertArrayNotHasKey('ip_address', $response->json('data.0'));
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    public function test_per_page_is_capped_by_page_size(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        foreach (range(1, 5) as $index) {
            ActivityLog::factory()->create([
                'user_id' => $user->id,
                'subject_type' => $project->getMorphClass(),
                'subject_id' => $project->id,
                'action' => 'project.updated',
                'description' => "Row {$index}",
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/activity?perPage=500')
            ->assertOk()
            ->assertJsonPath('meta.perPage', PageSize::MAX)
            ->assertJsonCount(5, 'data');
    }
}
