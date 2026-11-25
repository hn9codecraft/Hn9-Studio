<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\User;
use App\Services\ProviderCredentialService;
use App\Support\RuntimeProviderCredentials;
use Database\Seeders\AiProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ClaudeOpenRouterCredentialSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key-1234';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'hn9.runtime_credentials.enabled' => true,
            'ai.providers.claude.api_key' => null,
            'ai.providers.claude.enabled' => false,
            'ai.providers.claude.base_url' => 'https://api.anthropic.com',
            'ai.providers.claude.version' => '2026-10-21',
            'ai.providers.openrouter.api_key' => null,
            'ai.providers.openrouter.enabled' => false,
            'ai.providers.openrouter.base_url' => 'https://openrouter.ai/api/v1',
        ]);
    }

    public function test_admin_saves_masks_and_tests_claude_and_openrouter_without_usage_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->putJson('/api/v1/provider-credentials/claude', ['api_key' => self::KEY])
            ->assertForbidden();

        $saved = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/provider-credentials/claude', ['api_key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('data.masked', '••••1234');
        $this->assertStringNotContainsString(self::KEY, $saved->getContent());
        $this->assertTrue((bool) config('ai.providers.claude.enabled'));

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/provider-credentials/openrouter', ['api_key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('data.masked', '••••1234');

        Http::fake([
            'https://api.anthropic.com/v1/models' => Http::response(['data' => []], 200),
        ]);
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/provider-credentials/claude/test')
            ->assertOk()
            ->assertJsonPath('data.result', 'connected');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.anthropic.com/v1/models'
            && $request->hasHeader('x-api-key', self::KEY)
            && $request->hasHeader('anthropic-version', '2026-10-21')
            && ! str_contains($request->url(), '/v1/messages'));

        Http::fake([
            'https://openrouter.ai/api/v1/key' => Http::response([
                'data' => [
                    'label' => 'sk-or-v1-au7...890',
                    'usage' => 12,
                    'limit_remaining' => 3,
                ],
            ], 200),
        ]);
        $openrouter = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/provider-credentials/openrouter/test')
            ->assertOk()
            ->assertJsonPath('data.result', 'connected');
        $body = $openrouter->getContent();
        $this->assertStringNotContainsString('limit_remaining', $body);
        $this->assertStringNotContainsString('sk-or-v1-au7', $body);
        $this->assertStringNotContainsString('"usage"', $body);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://openrouter.ai/api/v1/key'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && ! str_contains($request->url(), 'chat/completions'));
    }

    public function test_a_non_empty_env_key_wins_for_claude_and_openrouter(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(ProviderCredentialService::class);
        $service->setKey('claude', self::KEY, [], $admin);
        $service->setKey('openrouter', self::KEY, [], $admin);

        config([
            'ai.providers.claude.api_key' => 'env-claude-key',
            'ai.providers.openrouter.api_key' => 'env-openrouter-key',
        ]);
        app()->forgetInstance(RuntimeProviderCredentials::class);
        app(RuntimeProviderCredentials::class)->apply();

        $this->assertSame('env-claude-key', config('ai.providers.claude.api_key'));
        $this->assertSame('env-openrouter-key', config('ai.providers.openrouter.api_key'));
    }

    public function test_seeding_claude_and_openrouter_twice_keeps_one_row_each(): void
    {
        $this->seed(AiProviderSeeder::class);
        $this->seed(AiProviderSeeder::class);

        $this->assertSame(1, AiProvider::query()->where('slug', 'claude')->count());
        $this->assertSame(1, AiProvider::query()->where('slug', 'openrouter')->count());
    }
}
