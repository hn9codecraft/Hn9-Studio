<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectAssetType;
use App\Enums\ProjectStatus;
use App\Enums\ScriptStatus;
use App\Enums\VideoStatus;
use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use App\Support\DashboardActionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DashboardActionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_actions_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard/actions')->assertUnauthorized();
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=not-a-module')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['module']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?status=archived')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?project=not-a-uuid')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project']);
    }

    public function test_empty_account_returns_no_actions(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['total']);
        $this->assertSame(DashboardActionRules::LIMIT, $data['limit']);
        $this->assertSame([], $data['items']);
    }

    public function test_actions_include_real_failed_pending_processing_and_draft_states(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'name' => 'Attention Project',
            'status' => ProjectStatus::Active->value,
        ]);

        $failedImage = Image::factory()->for($project)->create([
            'title' => 'Broken still',
            'status' => ImageStatus::Failed->value,
            'prompt' => 'SECRET_PROMPT',
            'created_at' => Carbon::parse('2026-09-08 12:00:00'),
        ]);
        Image::factory()->for($project)->create([
            'title' => 'Waiting still',
            'status' => ImageStatus::Pending->value,
            'created_at' => Carbon::parse('2026-09-08 11:00:00'),
        ]);
        Image::factory()->for($project)->create([
            'title' => 'Rendering still',
            'status' => ImageStatus::Processing->value,
            'created_at' => Carbon::parse('2026-09-08 10:30:00'),
        ]);
        Video::factory()->for($project)->create([
            'title' => 'Broken clip',
            'status' => VideoStatus::Failed->value,
            'prompt' => 'SECRET_VIDEO_PROMPT',
            'created_at' => Carbon::parse('2026-09-08 11:30:00'),
        ]);
        Script::factory()->for($project)->create([
            'title' => 'Unfinished script',
            'status' => ScriptStatus::Draft->value,
            'body' => 'SECRET_SCRIPT_BODY',
            'created_at' => Carbon::parse('2026-09-08 09:00:00'),
        ]);
        ProjectAsset::factory()->for($project)->create([
            'title' => 'Unfinished asset',
            'type' => ProjectAssetType::Image->value,
            'status' => ProjectAssetStatus::Draft->value,
            'created_at' => Carbon::parse('2026-09-08 08:00:00'),
        ]);
        Project::factory()->for($user)->create([
            'name' => 'Draft project',
            'status' => ProjectStatus::Draft->value,
            'created_at' => Carbon::parse('2026-09-08 07:00:00'),
        ]);

        Image::factory()->for($project)->create(['status' => ImageStatus::Completed->value]);
        Image::factory()->for($project)->create(['status' => ImageStatus::Archived->value]);
        Video::factory()->for($project)->create(['status' => VideoStatus::Archived->value]);
        Script::factory()->for($project)->create(['status' => ScriptStatus::Ready->value]);
        ProjectAsset::factory()->for($project)->create(['status' => ProjectAssetStatus::Ready->value]);
        Project::factory()->for($user)->create(['status' => ProjectStatus::Completed->value]);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions')
            ->assertOk()
            ->json('data');

        $this->assertSame(7, $data['total']);
        $this->assertCount(7, $data['items']);
        $this->assertSame('failed_image', $data['items'][0]['type']);
        $this->assertSame('high', $data['items'][0]['priority']);
        $this->assertSame($failedImage->uuid, $data['items'][0]['id']);
        $this->assertSame('image', $data['items'][0]['module']);
        $this->assertSame('failed', $data['items'][0]['status']);
        $this->assertSame('Broken still', $data['items'][0]['title']);
        $this->assertSame('Image request failed.', $data['items'][0]['description']);
        $this->assertSame($project->uuid, $data['items'][0]['project']['id']);
        $this->assertSame(
            '/projects/'.$project->uuid.'/images/'.$failedImage->uuid,
            $data['items'][0]['action_url'],
        );

        $priorities = array_column($data['items'], 'priority');
        $highCount = count(array_filter($priorities, fn (string $priority): bool => $priority === 'high'));
        $mediumCount = count(array_filter($priorities, fn (string $priority): bool => $priority === 'medium'));
        $this->assertSame(2, $highCount);
        $this->assertSame(2, $mediumCount);
        $this->assertSame('high', $data['items'][0]['priority']);
        $this->assertSame('high', $data['items'][1]['priority']);
        $this->assertSame('medium', $data['items'][2]['priority']);
        $this->assertSame('low', $data['items'][array_key_last($data['items'])]['priority']);

        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET_PROMPT', $encoded);
        $this->assertStringNotContainsString('SECRET_VIDEO_PROMPT', $encoded);
        $this->assertStringNotContainsString('SECRET_SCRIPT_BODY', $encoded);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('project_id', $data['items'][0]);
        $this->assertArrayNotHasKey('prompt', $data['items'][0]);
    }

    public function test_actions_are_isolated_between_users_and_admins_stay_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $owned = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        Image::factory()->for($owned)->create(['status' => ImageStatus::Failed->value, 'title' => 'Owner failed']);

        $foreign = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);
        Image::factory()->for($foreign)->create(['status' => ImageStatus::Failed->value, 'title' => 'Intruder failed']);

        $ownerData = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/actions')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $ownerData['total']);
        $this->assertSame('Owner failed', $ownerData['items'][0]['title']);

        $intruderData = $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/dashboard/actions')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $intruderData['total']);
        $this->assertSame('Intruder failed', $intruderData['items'][0]['title']);

        $adminData = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/actions')
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $adminData['total']);
        $encoded = json_encode($adminData, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Owner failed', $encoded);
        $this->assertStringNotContainsString('Intruder failed', $encoded);
    }

    public function test_soft_deleted_records_and_projects_are_excluded(): void
    {
        $user = User::factory()->create();
        $live = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Image::factory()->for($live)->create(['status' => ImageStatus::Failed->value, 'title' => 'Live failed']);

        $deletedChild = Image::factory()->for($live)->create(['status' => ImageStatus::Failed->value, 'title' => 'Deleted image']);
        $deletedChild->delete();

        $deletedProject = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        Image::factory()->for($deletedProject)->create(['status' => ImageStatus::Failed->value, 'title' => 'Deleted project image']);
        $deletedProject->delete();

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['total']);
        $this->assertSame('Live failed', $data['items'][0]['title']);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Deleted image', $encoded);
        $this->assertStringNotContainsString('Deleted project image', $encoded);
    }

    public function test_module_status_and_project_filters(): void
    {
        $user = User::factory()->create();
        $alpha = Project::factory()->for($user)->create(['name' => 'Alpha', 'status' => ProjectStatus::Active->value]);
        $beta = Project::factory()->for($user)->create(['name' => 'Beta', 'status' => ProjectStatus::Active->value]);

        Image::factory()->for($alpha)->create(['status' => ImageStatus::Failed->value, 'title' => 'Alpha failed']);
        Image::factory()->for($alpha)->create(['status' => ImageStatus::Pending->value, 'title' => 'Alpha pending']);
        Video::factory()->for($beta)->create(['status' => VideoStatus::Failed->value, 'title' => 'Beta failed']);
        Script::factory()->for($alpha)->create(['status' => ScriptStatus::Draft->value, 'title' => 'Alpha draft script']);

        ProjectAsset::factory()->for($alpha)->create(['status' => ProjectAssetStatus::Draft->value, 'title' => 'Alpha draft asset']);
        Project::factory()->for($user)->create(['name' => 'Draft only', 'status' => ProjectStatus::Draft->value]);

        $images = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=image')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $images['total']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=video')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.module', 'video');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=script')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.module', 'script');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=asset')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.module', 'asset');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=project')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.module', 'project');

        $failed = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?status=failed')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $failed['total']);
        $titles = array_column($failed['items'], 'title');
        $this->assertContains('Alpha failed', $titles);
        $this->assertContains('Beta failed', $titles);

        $project = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?project='.$alpha->uuid)
            ->assertOk()
            ->json('data');
        $this->assertSame(4, $project['total']);
        foreach ($project['items'] as $item) {
            $this->assertSame($alpha->uuid, $item['project']['id']);
        }

        $combo = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=script&status=failed')
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $combo['total']);
        $this->assertSame([], $combo['items']);
    }

    public function test_foreign_project_uuid_does_not_leak_data(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $foreign = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);
        Image::factory()->for($foreign)->create(['status' => ImageStatus::Failed->value, 'title' => 'Hidden failed']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?project='.$foreign->uuid)
            ->assertNotFound();
    }

    public function test_existing_dashboard_endpoints_still_work(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.projects.total', 1);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/analytics')->assertOk()->assertJsonPath('data.projects.total', 1);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/usage')->assertOk()->assertJsonPath('data.operations', 0);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/costs')->assertOk()->assertJsonPath('data.has_records', false);
    }

    public function test_returned_items_are_capped_while_total_is_uncapped(): void
    {
        $user = User::factory()->create();

        Project::factory()->count(DashboardActionRules::LIMIT + 1)->for($user)->create([
            'status' => ProjectStatus::Draft->value,
        ]);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/actions?module=project')
            ->assertOk()
            ->json('data');

        $this->assertSame(DashboardActionRules::LIMIT + 1, $data['total']);
        $this->assertSame(DashboardActionRules::LIMIT, $data['limit']);
        $this->assertCount(DashboardActionRules::LIMIT, $data['items']);
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
