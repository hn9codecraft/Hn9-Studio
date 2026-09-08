<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectAssetType;
use App\Enums\ProjectStatus;
use App\Enums\ScriptStatus;
use App\Enums\VideoStatus;
use App\Models\ActivityLog;
use App\Models\GeneratedAsset;
use App\Models\GeneratedContent;
use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DashboardAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard/analytics')->assertUnauthorized();
    }

    public function test_invalid_date_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics?from=not-a-date')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics?to=2026-13-40')
            ->assertStatus(422);
    }

    public function test_from_after_to_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_empty_user_receives_zeros_and_empty_collections(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['projects']['total']);
        $this->assertSame(0, $data['projects']['draft']);
        $this->assertSame(0, $data['projects']['active']);
        $this->assertSame(0, $data['projects']['completed']);
        $this->assertSame(0, $data['projects']['archived']);
        $this->assertSame([], $data['projects']['timeline']);

        $this->assertSame(0, $data['content']['scripts']['total']);
        $this->assertSame(0, $data['content']['scripts']['draft']);
        $this->assertSame(0, $data['content']['images']['total']);
        $this->assertSame(0, $data['content']['videos']['total']);
        $this->assertSame(0, $data['content']['assets']['total']);

        $this->assertSame([], $data['creation_timeline']);
        $this->assertSame([], $data['project_productivity']);
        $this->assertSame(0, $data['activity']['total']);
        $this->assertSame(0, $data['activity']['recent_count']);
        $this->assertSame(0, $data['activity']['by_module']['project']);
        $this->assertSame(0, $data['activity']['by_module']['script']);
        $this->assertSame(0, $data['activity']['by_module']['image']);
        $this->assertSame(0, $data['activity']['by_module']['video']);
        $this->assertSame(0, $data['activity']['by_module']['asset']);
        $this->assertSame([], $data['activity']['by_action']);
        $this->assertArrayNotHasKey('usage', $data);
        $this->assertArrayNotHasKey('costs', $data);
    }

    public function test_status_breakdown_matches_real_records(): void
    {
        $user = User::factory()->create();
        $this->seedStudio($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 4)
            ->assertJsonPath('data.projects.draft', 1)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.projects.completed', 1)
            ->assertJsonPath('data.projects.archived', 1)
            ->assertJsonPath('data.content.scripts.total', 2)
            ->assertJsonPath('data.content.scripts.draft', 1)
            ->assertJsonPath('data.content.scripts.ready', 1)
            ->assertJsonPath('data.content.images.total', 2)
            ->assertJsonPath('data.content.images.draft', 1)
            ->assertJsonPath('data.content.images.pending', 1)
            ->assertJsonPath('data.content.videos.total', 2)
            ->assertJsonPath('data.content.videos.draft', 1)
            ->assertJsonPath('data.content.videos.archived', 1)
            ->assertJsonPath('data.content.assets.total', 2)
            ->assertJsonPath('data.content.assets.draft', 1)
            ->assertJsonPath('data.content.assets.ready', 1);
    }

    public function test_user_cannot_see_another_users_analytics(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->seedStudio($owner);

        $other = Project::factory()->for($intruder)->create([
            'name' => 'Intruder campaign',
            'status' => ProjectStatus::Active->value,
        ]);
        $this->stamp($other, '2026-09-07 09:00:00');
        Script::factory()->for($other)->create(['status' => ScriptStatus::Ready->value]);
        Image::factory()->for($other)->create(['status' => ImageStatus::Pending->value]);
        Video::factory()->for($other)->create(['status' => VideoStatus::Pending->value]);
        $otherAsset = ProjectAsset::factory()->for($other)->create([
            'type' => ProjectAssetType::Image->value,
            'status' => ProjectAssetStatus::Ready->value,
        ]);
        ActivityLog::factory()->create([
            'user_id' => $intruder->id,
            'subject_type' => $other->getMorphClass(),
            'subject_id' => $other->id,
            'action' => 'project.created',
            'description' => 'Intruder project created',
        ]);
        ActivityLog::factory()->create([
            'user_id' => $intruder->id,
            'subject_type' => $otherAsset->getMorphClass(),
            'subject_id' => $otherAsset->id,
            'action' => 'project_asset.created',
        ]);

        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $data['projects']['total']);
        $this->assertSame(2, $data['content']['scripts']['total']);
        $this->assertSame(2, $data['content']['images']['total']);
        $this->assertSame(2, $data['content']['videos']['total']);
        $this->assertSame(2, $data['content']['assets']['total']);

        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Intruder', $payload);
        $this->assertStringNotContainsString($other->uuid, $payload);
        $this->assertStringNotContainsString($otherAsset->uuid, $payload);
    }

    public function test_timeline_uses_real_created_at_dates_and_excludes_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $first = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $this->stamp($first, '2026-09-01 12:00:00');
        $script = Script::factory()->for($first)->create(['status' => ScriptStatus::Draft->value]);
        $this->stamp($script, '2026-09-01 13:00:00');

        $second = Project::factory()->for($owner)->create(['status' => ProjectStatus::Draft->value]);
        $this->stamp($second, '2026-09-03 08:00:00');
        $image = Image::factory()->for($second)->create(['status' => ImageStatus::Draft->value]);
        $this->stamp($image, '2026-09-03 09:00:00');

        $foreign = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);
        $this->stamp($foreign, '2026-09-01 12:00:00');

        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->json('data');

        $this->assertSame([
            ['date' => '2026-09-01', 'count' => 1],
            ['date' => '2026-09-03', 'count' => 1],
        ], $data['projects']['timeline']);

        $this->assertSame([
            [
                'date' => '2026-09-01',
                'projects' => 1,
                'scripts' => 1,
                'images' => 0,
                'videos' => 0,
                'assets' => 0,
            ],
            [
                'date' => '2026-09-03',
                'projects' => 1,
                'scripts' => 0,
                'images' => 1,
                'videos' => 0,
                'assets' => 0,
            ],
        ], $data['creation_timeline']);
    }

    public function test_date_filter_limits_counts_and_timeline(): void
    {
        $user = User::factory()->create();
        $inRange = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $this->stamp($inRange, '2026-09-05 10:00:00');
        $outOfRange = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        $this->stamp($outOfRange, '2026-08-01 10:00:00');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 1)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.projects.draft', 0)
            ->assertJsonPath('data.projects.timeline.0.date', '2026-09-05')
            ->assertJsonCount(1, 'data.projects.timeline');
    }

    public function test_activity_classifies_project_asset_separately_from_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        $asset = ProjectAsset::factory()->for($project)->create(['type' => ProjectAssetType::Image->value]);

        ActivityLog::factory()->create([
            'user_id' => $user->id,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
            'action' => 'project.created',
            'created_at' => now()->subMinutes(2),
        ]);
        ActivityLog::factory()->create([
            'user_id' => $user->id,
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'action' => 'project_asset.created',
            'created_at' => now()->subMinute(),
        ]);

        $activity = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->json('data.activity');

        $this->assertSame(2, $activity['total']);
        $this->assertSame(1, $activity['by_module']['project']);
        $this->assertSame(1, $activity['by_module']['asset']);
        $this->assertSame(0, $activity['by_module']['script']);
        $this->assertSame(1, $activity['by_action']['project.created']);
        $this->assertSame(1, $activity['by_action']['project_asset.created']);
        $this->assertGreaterThanOrEqual(2, $activity['recent_count']);
    }

    public function test_activity_is_isolated_from_other_users(): void
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
        ]);
        ActivityLog::factory()->count(5)->create([
            'user_id' => $intruder->id,
            'subject_type' => $foreign->getMorphClass(),
            'subject_id' => $foreign->id,
            'action' => 'project.created',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->assertJsonPath('data.activity.total', 1)
            ->assertJsonPath('data.activity.by_module.project', 1);
    }

    public function test_productivity_counts_owned_projects_and_excludes_soft_deletes(): void
    {
        $user = User::factory()->create();
        $live = Project::factory()->for($user)->create([
            'name' => 'Live campaign',
            'status' => ProjectStatus::Active->value,
        ]);
        Script::factory()->for($live)->count(2)->create(['status' => ScriptStatus::Draft->value]);
        Image::factory()->for($live)->create(['status' => ImageStatus::Draft->value]);
        Video::factory()->for($live)->create(['status' => VideoStatus::Draft->value]);
        ProjectAsset::factory()->for($live)->count(3)->create([
            'type' => ProjectAssetType::Image->value,
            'status' => ProjectAssetStatus::Draft->value,
        ]);
        $deletedScript = Script::factory()->for($live)->create(['status' => ScriptStatus::Ready->value]);
        $deletedScript->delete();

        $deletedProject = Project::factory()->for($user)->create(['name' => 'Gone', 'status' => ProjectStatus::Active->value]);
        Script::factory()->for($deletedProject)->count(4)->create();
        $deletedProject->delete();

        GeneratedContent::factory()->for($live)->count(7)->create();
        GeneratedAsset::factory()->for($live)->count(7)->create();

        $other = User::factory()->create();
        $foreign = Project::factory()->for($other)->create(['name' => 'Not mine']);
        Script::factory()->for($foreign)->count(9)->create();

        $rows = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->json('data.project_productivity');

        $this->assertCount(1, $rows);
        $this->assertSame($live->uuid, $rows[0]['project']['id']);
        $this->assertSame('Live campaign', $rows[0]['project']['name']);
        $this->assertSame(2, $rows[0]['scripts']);
        $this->assertSame(1, $rows[0]['images']);
        $this->assertSame(1, $rows[0]['videos']);
        $this->assertSame(3, $rows[0]['assets']);
        $this->assertSame(7, $rows[0]['total_items']);
    }

    public function test_admin_analytics_remain_owner_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        Project::factory()->for($admin)->create(['status' => ProjectStatus::Draft->value]);
        $memberProject = Project::factory()->for($member)->create(['status' => ProjectStatus::Active->value]);
        Script::factory()->for($memberProject)->count(4)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/analytics')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 1)
            ->assertJsonPath('data.content.scripts.total', 0)
            ->assertJsonCount(1, 'data.project_productivity');
    }

    private function seedStudio(User $user): void
    {
        Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        $active = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Project::factory()->for($user)->create(['status' => ProjectStatus::Completed->value]);
        Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);

        Script::factory()->for($active)->create(['status' => ScriptStatus::Draft->value]);
        Script::factory()->for($active)->create(['status' => ScriptStatus::Ready->value]);
        Image::factory()->for($active)->create(['status' => ImageStatus::Draft->value]);
        Image::factory()->for($active)->create(['status' => ImageStatus::Pending->value]);
        Video::factory()->for($active)->create(['status' => VideoStatus::Draft->value]);
        Video::factory()->for($active)->create(['status' => VideoStatus::Archived->value]);
        ProjectAsset::factory()->for($active)->create([
            'type' => ProjectAssetType::Image->value,
            'status' => ProjectAssetStatus::Draft->value,
        ]);
        ProjectAsset::factory()->for($active)->create([
            'type' => ProjectAssetType::Video->value,
            'status' => ProjectAssetStatus::Ready->value,
        ]);
    }

    private function stamp(Project|Script|Image|Video|ProjectAsset $model, string $timestamp): void
    {
        $model->forceFill([
            'created_at' => Carbon::parse($timestamp),
            'updated_at' => Carbon::parse($timestamp),
        ])->save();
    }
}
