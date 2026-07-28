<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Services\GenerationRequestServiceInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\Contracts\Services\ProviderRegistryServiceInterface;
use App\DTOs\Generation\GenerationRequestData;
use App\DTOs\Project\CreateProjectData;
use App\DTOs\Provider\ProviderData;
use App\Enums\ProjectStatus;
use App\Enums\Status;
use App\Exceptions\GenerationException;
use App\Exceptions\ProviderException;
use App\Exceptions\WorkflowException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_service_creates_unique_slug_and_logs_activity(): void
    {
        $user = User::factory()->create();
        $service = $this->app->make(ProjectServiceInterface::class);

        $first = $service->create(CreateProjectData::fromArray([
            'user_id' => $user->id,
            'name' => 'Summer Campaign',
        ]), $user);

        $second = $service->create(CreateProjectData::fromArray([
            'user_id' => $user->id,
            'name' => 'Summer Campaign',
        ]), $user);

        $this->assertSame('summer-campaign', $first->slug);
        $this->assertSame('summer-campaign-2', $second->slug);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project.created',
            'subject_id' => $first->id,
        ]);
    }

    public function test_project_service_rejects_illegal_status_transition(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);
        $service = $this->app->make(ProjectServiceInterface::class);

        $this->expectException(WorkflowException::class);

        $service->changeStatus($project, ProjectStatus::Completed, $user);
    }

    public function test_generation_request_is_rejected_for_non_editable_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);
        $service = $this->app->make(GenerationRequestServiceInterface::class);

        $this->expectException(GenerationException::class);

        $service->submit($project, GenerationRequestData::fromArray([
            'project_id' => $project->id,
            'deliverable_type' => 'reel',
            'language' => 'en',
        ]), $user);
    }

    public function test_generation_request_persists_brief_for_editable_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        $service = $this->app->make(GenerationRequestServiceInterface::class);

        $input = $service->submit($project, GenerationRequestData::fromArray([
            'project_id' => $project->id,
            'deliverable_type' => 'reel',
            'language' => 'en',
            'topic' => 'Launch',
        ]), $user);

        $this->assertDatabaseHas('project_inputs', [
            'id' => $input->id,
            'project_id' => $project->id,
            'deliverable_type' => 'reel',
        ]);
    }

    public function test_provider_registry_registers_resolves_and_guards_status(): void
    {
        $service = $this->app->make(ProviderRegistryServiceInterface::class);

        $service->register(ProviderData::fromArray([
            'slug' => 'openai',
            'name' => 'OpenAI',
            'category' => 'llm',
            'status' => Status::Active->value,
            'priority' => 10,
        ]));

        $this->assertTrue($service->has('openai'));
        $this->assertSame('openai', $service->get('openai')->slug);

        $this->expectException(ProviderException::class);
        $service->register(ProviderData::fromArray([
            'slug' => 'openai',
            'name' => 'Duplicate',
            'category' => 'llm',
        ]));
    }

    public function test_provider_registry_get_throws_for_inactive_provider(): void
    {
        $service = $this->app->make(ProviderRegistryServiceInterface::class);

        $service->register(ProviderData::fromArray([
            'slug' => 'muted',
            'name' => 'Muted',
            'category' => 'llm',
            'status' => Status::Disabled->value,
        ]));

        $this->expectException(ProviderException::class);
        $service->get('muted');
    }
}
