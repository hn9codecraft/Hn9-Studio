<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProviderCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XaiConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'xai-live-key-9876';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'hn9.runtime_credentials.enabled' => true,
            'ai.providers.xai.api_key' => null,
            'ai.providers.xai.base_url' => 'https://api.x.ai/v1',
        ]);
    }

    public function test_admin_probe_is_get_models_and_a_member_cannot_test(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['role' => 'member']);
        app(ProviderCredentialService::class)->setKey('xai', self::KEY, [], $admin);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/v1/provider-credentials/xai/test')
            ->assertForbidden();
        Http::assertNothingSent();

        Http::fake([
            'https://api.x.ai/v1/models' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([], 401)
                ->push([], 429),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/provider-credentials/xai/test')
            ->assertOk()
            ->assertJsonPath('data.result', 'connected');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.x.ai/v1/models'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/provider-credentials/xai/test')
            ->assertOk()
            ->assertJsonPath('data.result', 'invalid_key');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/provider-credentials/xai/test')
            ->assertOk()
            ->assertJsonPath('data.result', 'rate_limited');
    }

    public function test_a_missing_key_makes_no_request(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/provider-credentials/xai/test')
            ->assertOk()
            ->assertJsonPath('data.result', 'invalid_key');
        Http::assertNothingSent();
    }
}
