<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_command_promotes_demotes_and_refuses_the_last_admin(): void
    {
        $member = User::factory()->create(['email' => 'Test@Example.com', 'role' => 'member']);

        $this->artisan('hn9:user-role', ['email' => 'test@example.com', 'role' => 'admin'])
            ->expectsOutput('Role for Test@Example.com set to admin.')
            ->assertExitCode(0);
        $this->assertSame('admin', $member->fresh()->role);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.role_changed']);
        $this->assertSame(['old' => 'member', 'new' => 'admin'], ActivityLog::query()->where('action', 'user.role_changed')->first()->properties);

        $this->artisan('hn9:user-role', ['email' => 'test@example.com', 'role' => 'nope'])
            ->expectsOutput('Role must be admin or member.')
            ->assertExitCode(1);

        $this->artisan('hn9:user-role', ['email' => 'missing@example.com', 'role' => 'member'])
            ->expectsOutput('User not found')
            ->assertExitCode(1);

        $this->artisan('hn9:user-role', ['email' => 'test@example.com', 'role' => 'member'])
            ->expectsOutput('The last active admin cannot be demoted.')
            ->assertExitCode(1);
        $this->assertSame('admin', $member->fresh()->role);

        User::factory()->admin()->create();
        $this->artisan('hn9:user-role', ['email' => 'test@example.com', 'role' => 'member'])
            ->assertExitCode(0);
        $this->assertSame('member', $member->fresh()->role);
    }
}
