<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AssetType;
use App\Enums\ExecutionStatus;
use App\Enums\MediaType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderType;
use App\Enums\WorkflowStatus;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_backed_enum_helpers_expose_values_and_options(): void
    {
        $this->assertContains('draft', ProjectStatus::values());
        $this->assertContains('archived', ProjectStatus::values());
        $this->assertArrayHasKey('llm', ProviderType::options());
        $this->assertSame('LLM', ProviderType::options()['llm']);
        $this->assertTrue(ProjectStatus::isValid('active'));
        $this->assertFalse(ProjectStatus::isValid('nope'));
    }

    public function test_project_status_transitions_enforce_lifecycle(): void
    {
        $this->assertTrue(ProjectStatus::Draft->canTransitionTo(ProjectStatus::Active));
        $this->assertTrue(ProjectStatus::Active->canTransitionTo(ProjectStatus::Completed));
        $this->assertFalse(ProjectStatus::Completed->canTransitionTo(ProjectStatus::Draft));
        $this->assertTrue(ProjectStatus::Draft->isEditable());
        $this->assertFalse(ProjectStatus::Archived->isEditable());
    }

    public function test_execution_and_workflow_terminal_states(): void
    {
        $this->assertTrue(ExecutionStatus::Completed->isSuccessful());
        $this->assertTrue(ExecutionStatus::Failed->isFinished());
        $this->assertFalse(ExecutionStatus::Running->isFinished());
        $this->assertTrue(WorkflowStatus::Cancelled->isFinished());
        $this->assertTrue(WorkflowStatus::Running->isActive());
    }

    public function test_media_and_asset_type_mapping(): void
    {
        $this->assertSame(MediaType::Image, MediaType::fromMimeType('image/png'));
        $this->assertSame(MediaType::Audio, MediaType::fromMimeType('audio/mpeg'));
        $this->assertSame(MediaType::Document, MediaType::fromMimeType(null));
        $this->assertSame('images', AssetType::Thumbnail->disk());
        $this->assertSame('voice', AssetType::Voice->disk());
        $this->assertSame(MediaType::Image, AssetType::Thumbnail->mediaType());
    }
}
