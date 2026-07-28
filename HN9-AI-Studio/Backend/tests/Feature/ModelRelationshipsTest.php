<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\AiProvider;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_pipeline_relationships_resolve(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $run = WorkflowRun::factory()->for($project)->for($user)->create();
        AgentExecution::factory()->for($run)->create();
        GeneratedContent::factory()->for($project)->create();

        $this->assertSame(1, $user->projects()->count());
        $this->assertSame(1, $project->workflowRuns()->count());
        $this->assertSame(1, $run->agentExecutions()->count());
        $this->assertSame(1, $project->generatedContents()->count());
        $this->assertSame($user->id, $project->user->id);
    }

    public function test_uuid_is_auto_assigned_on_create(): void
    {
        $project = Project::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $project->uuid
        );
    }

    public function test_provider_setting_secret_is_encrypted_at_rest(): void
    {
        $provider = AiProvider::factory()->create();

        $setting = ProviderSetting::factory()
            ->for($provider, 'provider')
            ->secret()
            ->create(['value' => 'sk-secret-value']);

        // Stored ciphertext differs from plaintext, but the accessor decrypts it.
        $rawValue = DB::table('provider_settings')->where('id', $setting->id)->value('value');

        $this->assertNotSame('sk-secret-value', $rawValue);
        $this->assertSame('sk-secret-value', $setting->fresh()->value);
        $this->assertSame('********', $setting->fresh()->maskedValue());
    }

    public function test_soft_deletes_hide_records_but_retain_them(): void
    {
        $project = Project::factory()->create();
        $project->delete();

        $this->assertSoftDeleted($project);
        $this->assertSame(0, Project::query()->count());
        $this->assertSame(1, Project::withTrashed()->count());
    }
}
