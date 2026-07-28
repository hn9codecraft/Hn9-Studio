<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): ProjectRepositoryInterface
    {
        return $this->app->make(ProjectRepositoryInterface::class);
    }

    public function test_repository_persists_and_finds_by_id_and_uuid(): void
    {
        $user = User::factory()->create();
        $repo = $this->repository();

        $project = $repo->create([
            'user_id' => $user->id,
            'name' => 'Repo Project',
            'slug' => 'repo-project',
        ]);

        $this->assertInstanceOf(Project::class, $project);
        $this->assertNotEmpty($project->uuid);
        $this->assertTrue($repo->find($project->id)->is($project));
        $this->assertTrue($repo->findByUuid($project->uuid)->is($project));
    }

    public function test_slug_uniqueness_check_is_scoped_to_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $repo = $this->repository();

        Project::factory()->for($user)->create(['slug' => 'taken']);

        $this->assertTrue($repo->slugExistsForUser($user->id, 'taken'));
        $this->assertFalse($repo->slugExistsForUser($other->id, 'taken'));
    }

    public function test_paginate_for_user_only_returns_that_users_projects(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(3)->for($user)->create();
        Project::factory()->count(2)->create();

        $page = $this->repository()->paginateForUser($user->id);

        $this->assertSame(3, $page->total());
    }
}
