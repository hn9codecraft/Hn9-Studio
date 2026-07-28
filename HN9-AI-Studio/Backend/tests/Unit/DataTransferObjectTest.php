<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\Project\CreateProjectData;
use App\DTOs\Project\UpdateProjectData;
use App\Enums\ProjectStatus;
use PHPUnit\Framework\TestCase;

class DataTransferObjectTest extends TestCase
{
    public function test_create_project_data_from_array_applies_defaults(): void
    {
        $data = CreateProjectData::fromArray([
            'user_id' => 7,
            'name' => 'Launch Reel',
        ]);

        $this->assertSame(7, $data->user_id);
        $this->assertSame('Launch Reel', $data->name);
        $this->assertSame(ProjectStatus::Draft->value, $data->status);

        $array = $data->toArray();
        $this->assertArrayNotHasKey('slug', $array, 'null values must be dropped');
        $this->assertArrayNotHasKey('description', $array);
        $this->assertSame([], $array['settings']);
    }

    public function test_update_project_data_drops_nulls_for_patch_semantics(): void
    {
        $data = UpdateProjectData::fromArray(['name' => 'Renamed']);

        $this->assertSame(['name' => 'Renamed'], $data->toArray());
    }

    public function test_dtos_are_immutable_readonly(): void
    {
        $data = CreateProjectData::fromArray(['user_id' => 1, 'name' => 'X']);

        $this->expectException(\Error::class);
        $data->name = 'mutated';
    }
}
