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
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
    }

    public function test_empty_user_receives_zero_totals_and_empty_lists(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();

        $data = $response->json('data');

        $this->assertSame(0, $data['projects']['total']);
        $this->assertSame(0, $data['projects']['draft']);
        $this->assertSame(0, $data['projects']['active']);
        $this->assertSame(0, $data['projects']['completed']);
        $this->assertSame(0, $data['projects']['archived']);

        $this->assertSame(0, $data['scripts']['total']);
        $this->assertSame(0, $data['scripts']['by_status']['draft']);
        $this->assertSame(0, $data['images']['total']);
        $this->assertSame(0, $data['videos']['total']);
        $this->assertSame(0, $data['assets']['total']);

        $this->assertSame([], $data['recent_projects']);
        $this->assertSame([], $data['recent_activity']);
        $this->assertArrayNotHasKey('usage', $data);
        $this->assertArrayNotHasKey('costs', $data);
    }

    public function test_seeded_owner_counts_match_the_database(): void
    {
        $user = User::factory()->create();
        $this->seedOwnerStudio($user);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();

        $response
            ->assertJsonPath('data.projects.total', 4)
            ->assertJsonPath('data.projects.draft', 1)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.projects.completed', 1)
            ->assertJsonPath('data.projects.archived', 1)
            ->assertJsonPath('data.scripts.total', 2)
            ->assertJsonPath('data.scripts.by_status.draft', 1)
            ->assertJsonPath('data.scripts.by_status.ready', 1)
            ->assertJsonPath('data.images.total', 2)
            ->assertJsonPath('data.images.by_status.draft', 1)
            ->assertJsonPath('data.images.by_status.pending', 1)
            ->assertJsonPath('data.videos.total', 2)
            ->assertJsonPath('data.videos.by_status.draft', 1)
            ->assertJsonPath('data.videos.by_status.archived', 1)
            ->assertJsonPath('data.assets.total', 2)
            ->assertJsonPath('data.assets.by_status.draft', 1)
            ->assertJsonPath('data.assets.by_status.ready', 1);
    }

    public function test_user_cannot_see_another_users_studio_data(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->seedOwnerStudio($owner);

        $otherProject = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);
        Script::factory()->for($otherProject)->create(['status' => ScriptStatus::Ready->value]);
        Image::factory()->for($otherProject)->create(['status' => ImageStatus::Pending->value]);
        Video::factory()->for($otherProject)->create(['status' => VideoStatus::Pending->value]);
        $otherAsset = ProjectAsset::factory()->for($otherProject)->create([
            'title' => 'Intruder logo',
            'type' => ProjectAssetType::Image->value,
            'status' => ProjectAssetStatus::Ready->value,
        ]);
        ActivityLog::factory()->create([
            'user_id' => $intruder->id,
            'subject_type' => $otherProject->getMorphClass(),
            'subject_id' => $otherProject->id,
            'action' => 'project.created',
            'description' => 'Intruder project created',
            'ip_address' => '203.0.113.88',
        ]);
        ActivityLog::factory()->create([
            'user_id' => $intruder->id,
            'subject_type' => $otherAsset->getMorphClass(),
            'subject_id' => $otherAsset->id,
            'action' => 'project_asset.created',
            'description' => 'Intruder asset created',
        ]);

        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $data['projects']['total']);
        $this->assertSame(2, $data['scripts']['total']);
        $this->assertSame(2, $data['images']['total']);
        $this->assertSame(2, $data['videos']['total']);
        $this->assertSame(2, $data['assets']['total']);

        $projectIds = array_column($data['recent_projects'], 'id');
        $this->assertNotContains($otherProject->uuid, $projectIds);

        $activity = json_encode($data['recent_activity'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Intruder', $activity);
        $this->assertStringNotContainsString($otherProject->uuid, $activity);
        $this->assertStringNotContainsString($otherAsset->uuid, $activity);
    }

    public function test_soft_deleted_records_are_excluded(): void
    {
        $user = User::factory()->create();
        $live = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Script::factory()->for($live)->create(['status' => ScriptStatus::Draft->value]);
        Image::factory()->for($live)->create(['status' => ImageStatus::Draft->value]);
        Video::factory()->for($live)->create(['status' => VideoStatus::Draft->value]);
        ProjectAsset::factory()->for($live)->create([
            'type' => ProjectAssetType::Document->value,
            'status' => ProjectAssetStatus::Draft->value,
        ]);

        $deletedProject = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Script::factory()->for($deletedProject)->create(['status' => ScriptStatus::Ready->value]);
        Image::factory()->for($deletedProject)->create(['status' => ImageStatus::Pending->value]);
        Video::factory()->for($deletedProject)->create(['status' => VideoStatus::Pending->value]);
        ProjectAsset::factory()->for($deletedProject)->create([
            'type' => ProjectAssetType::Audio->value,
            'status' => ProjectAssetStatus::Ready->value,
        ]);
        $deletedProject->delete();

        $deletedScript = Script::factory()->for($live)->create(['status' => ScriptStatus::Ready->value]);
        $deletedScript->delete();
        $deletedImage = Image::factory()->for($live)->create(['status' => ImageStatus::Pending->value]);
        $deletedImage->delete();
        $deletedVideo = Video::factory()->for($live)->create(['status' => VideoStatus::Pending->value]);
        $deletedVideo->delete();
        $deletedAsset = ProjectAsset::factory()->for($live)->create([
            'type' => ProjectAssetType::Other->value,
            'status' => ProjectAssetStatus::Ready->value,
        ]);
        $deletedAsset->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 1)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.scripts.total', 1)
            ->assertJsonPath('data.scripts.by_status.ready', 0)
            ->assertJsonPath('data.images.total', 1)
            ->assertJsonPath('data.images.by_status.pending', 0)
            ->assertJsonPath('data.videos.total', 1)
            ->assertJsonPath('data.videos.by_status.pending', 0)
            ->assertJsonPath('data.assets.total', 1)
            ->assertJsonPath('data.assets.by_status.ready', 0);
    }

    public function test_project_statuses_remain_distinct(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Project::factory()->for($user)->create(['status' => ProjectStatus::Completed->value]);
        Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 4)
            ->assertJsonPath('data.projects.draft', 1)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.projects.completed', 1)
            ->assertJsonPath('data.projects.archived', 1);
    }

    public function test_studio_counts_ignore_pipeline_generated_tables(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Script::factory()->for($project)->create(['status' => ScriptStatus::Draft->value]);
        Image::factory()->for($project)->create(['status' => ImageStatus::Draft->value]);
        Video::factory()->for($project)->create(['status' => VideoStatus::Draft->value]);
        ProjectAsset::factory()->for($project)->create([
            'type' => ProjectAssetType::Image->value,
            'status' => ProjectAssetStatus::Draft->value,
        ]);

        GeneratedContent::factory()->for($project)->count(5)->create();
        GeneratedAsset::factory()->for($project)->count(5)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.scripts.total', 1)
            ->assertJsonPath('data.images.total', 1)
            ->assertJsonPath('data.videos.total', 1)
            ->assertJsonPath('data.assets.total', 1);
    }

    public function test_recent_projects_are_capped_and_use_public_uuids(): void
    {
        $user = User::factory()->create();
        $projects = Project::factory()->for($user)->count(10)->create(['status' => ProjectStatus::Draft->value]);

        foreach ($projects as $index => $project) {
            $project->forceFill(['updated_at' => now()->subMinutes(10 - $index)])->save();
        }

        $newest = $projects->last();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();

        $recent = $response->json('data.recent_projects');
        $this->assertCount(8, $recent);
        $this->assertSame($newest->uuid, $recent[0]['id']);
        $this->assertTrue(Str::isUuid($recent[0]['id']));
        $this->assertArrayNotHasKey('user_id', $recent[0]);
    }

    public function test_activity_is_redacted_and_includes_parent_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'name' => 'Owner workspace',
            'status' => ProjectStatus::Active->value,
        ]);
        $asset = ProjectAsset::factory()->for($project)->create([
            'title' => 'Brand kit',
            'type' => ProjectAssetType::Image->value,
        ]);

        ActivityLog::factory()->create([
            'user_id' => $user->id,
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'action' => 'project_asset.created',
            'description' => 'Project asset created',
            'properties' => ['before' => ['secret' => 'hidden'], 'ip' => '203.0.113.10'],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'SecretAgent/1.0',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.recent_activity.0.action', 'project_asset.created')
            ->assertJsonPath('data.recent_activity.0.module', 'asset')
            ->assertJsonPath('data.recent_activity.0.subject.id', $asset->uuid)
            ->assertJsonPath('data.recent_activity.0.project.id', $project->uuid)
            ->assertJsonPath('data.recent_activity.0.project.name', 'Owner workspace');

        $item = $response->json('data.recent_activity.0');
        $this->assertTrue(Str::isUuid($item['id']));
        $this->assertArrayNotHasKey('ip_address', $item);
        $this->assertArrayNotHasKey('user_agent', $item);
        $this->assertArrayNotHasKey('properties', $item);
        $this->assertArrayNotHasKey('user_id', $item);
        $this->assertArrayNotHasKey('subject_id', $item);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('203.0.113.10', $body);
        $this->assertStringNotContainsString('SecretAgent/1.0', $body);
        $this->assertStringNotContainsString('"hidden"', $body);
    }

    public function test_project_asset_activity_is_not_classified_as_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        $asset = ProjectAsset::factory()->for($project)->create([
            'type' => ProjectAssetType::Image->value,
        ]);

        ActivityLog::factory()->create([
            'user_id' => $user->id,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->id,
            'action' => 'project.created',
            'description' => 'Project created',
            'created_at' => now()->subMinute(),
        ]);
        ActivityLog::factory()->create([
            'user_id' => $user->id,
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'action' => 'project_asset.created',
            'description' => 'Project asset created',
            'created_at' => now(),
        ]);

        $actions = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->json('data.recent_activity');

        $this->assertSame('project_asset.created', $actions[0]['action']);
        $this->assertSame('asset', $actions[0]['module']);
        $this->assertSame('project.created', $actions[1]['action']);
        $this->assertSame('project', $actions[1]['module']);
    }

    public function test_admin_dashboard_is_still_owner_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        Project::factory()->for($admin)->create(['status' => ProjectStatus::Draft->value]);
        $memberProject = Project::factory()->for($member)->count(3)->create(['status' => ProjectStatus::Active->value]);
        Script::factory()->for($memberProject->first())->count(4)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 1)
            ->assertJsonPath('data.projects.draft', 1)
            ->assertJsonPath('data.projects.active', 0)
            ->assertJsonPath('data.scripts.total', 0);
    }

    public function test_recent_activity_is_capped_at_fifteen(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        foreach (range(1, 20) as $index) {
            ActivityLog::factory()->create([
                'user_id' => $user->id,
                'subject_type' => $project->getMorphClass(),
                'subject_id' => $project->id,
                'action' => 'project.updated',
                'description' => "Update {$index}",
                'created_at' => now()->subSeconds(20 - $index),
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonCount(15, 'data.recent_activity');
    }

    /**
     * @return array{0: Project}
     */
    private function seedOwnerStudio(User $user): array
    {
        $draft = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value, 'name' => 'Draft work']);
        $active = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value, 'name' => 'Active work']);
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

        return [$draft];
    }
}
