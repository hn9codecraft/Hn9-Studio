<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgentApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_show_rejects_path_traversal(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agents/'.rawurlencode('../../README'));

        $response->assertNotFound();

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('# HN9', $body);
        $this->assertStringNotContainsString('HN9 AI Studio', $body);
    }

    public function test_agent_show_rejects_directory_separators(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/agents/'.rawurlencode('../README'))
            ->assertNotFound();
    }
}
