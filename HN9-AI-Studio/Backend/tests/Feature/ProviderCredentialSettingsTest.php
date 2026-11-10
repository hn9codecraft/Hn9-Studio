<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Config\PlatformConfig;
use App\Models\ActivityLog;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Services\ProviderCredentialService;
use App\Story\Services\StoryReadinessService;
use App\Story\Video\Adapters\LiveStoryVideoAdapterFactory;
use App\Support\RuntimeProviderCredentials;
use Database\Seeders\AiProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProviderCredentialSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key-1234';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'hn9.runtime_credentials.enabled' => true,
            'ai.providers.openai.api_key' => null,
            'ai.providers.openai.enabled' => false,
            'story_video.live_providers.runway.api_key' => null,
            'story_video.live_providers.luma.api_key' => null,
            'story_video.live_providers.seedance.api_key' => null,
        ]);
    }

    public function test_seeder_is_idempotent_and_writes_no_secrets_or_users(): void
    {
        $users = User::query()->count();
        $this->seed(AiProviderSeeder::class);
        $this->seed(AiProviderSeeder::class);

        $this->assertSame(8, AiProvider::query()->count());
        $this->assertSame(8, AiProvider::query()->whereNotNull('uuid')->count());
        $this->assertSame(0, ProviderSetting::query()->count());
        $this->assertSame($users, User::query()->count());
    }

    public function test_admin_saves_an_encrypted_openai_key_and_sees_only_the_mask(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/provider-credentials/openai', [
            'api_key' => self::KEY,
        ])->assertOk();

        $response->assertJsonPath('data.source', 'stored')
            ->assertJsonPath('data.masked', '••••1234');
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        $raw = DB::table('provider_settings')->value('value');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertSame(self::KEY, ProviderSetting::query()->first()->value);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/provider-credentials')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'openai')
            ->assertJsonPath('data.0.source', 'stored')
            ->assertJsonPath('data.0.masked', '••••1234');
    }

    public function test_overlay_prefers_env_and_uses_a_stored_key_when_env_is_empty(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(ProviderCredentialService::class);
        $service->setKey('openai', self::KEY, [], $admin);

        $this->assertSame(self::KEY, config('ai.providers.openai.api_key'));
        $this->assertTrue(config('ai.providers.openai.enabled'));
        $this->assertTrue(app(StoryReadinessService::class)->connections()['story_planning']);

        config(['ai.providers.openai.api_key' => 'env-key-9999']);
        app()->forgetInstance(RuntimeProviderCredentials::class);
        $fresh = app(RuntimeProviderCredentials::class);
        $fresh->apply();
        $this->assertSame('env-key-9999', config('ai.providers.openai.api_key'));

        app()->forgetInstance(RuntimeProviderCredentials::class);
        config(['ai.providers.openai.api_key' => null, 'ai.providers.openai.enabled' => false]);
        $service = app(ProviderCredentialService::class);
        $removed = $service->removeKey('openai', $admin);
        $this->assertSame('none', $removed['source']);
        $this->assertNull(config('ai.providers.openai.api_key'));
        $this->assertFalse(app(StoryReadinessService::class)->connections()['story_planning']);
    }

    public function test_video_provider_keys_reach_the_live_adapter_factory(): void
    {
        app(ProviderCredentialService::class)->setKey('runway', self::KEY, [], null);
        app(ProviderCredentialService::class)->setKey('luma', self::KEY, [], null);
        app(ProviderCredentialService::class)->setKey('seedance', self::KEY, [], null);

        $this->assertSame(self::KEY, config('story_video.live_providers.runway.api_key'));
        $this->assertSame(self::KEY, config('story_video.live_providers.luma.api_key'));
        $this->assertSame(self::KEY, config('story_video.live_providers.seedance.api_key'));

        $keys = array_map(
            static fn ($adapter): string => $adapter->key(),
            app(LiveStoryVideoAdapterFactory::class)->fromConfig(config('story_video.live_providers'), false),
        );
        $this->assertContains('video.runway', $keys);
        $this->assertContains('video.luma', $keys);
        $this->assertContains('video.seedance', $keys);
    }

    public function test_overlay_is_fail_safe_and_does_not_log_a_secret(): void
    {
        $provider = app(AiProviderSeeder::class)->ensure('openai');
        $secret = 'test-key-1234';
        DB::table('provider_settings')->insert([
            'uuid' => (string) Str::uuid(),
            'ai_provider_id' => $provider->id,
            'key' => 'api_key',
            'value' => $secret,
            'is_secret' => true,
            'environment' => (string) config('app.env'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lines = [];
        Log::listen(static function ($log) use (&$lines): void {
            $lines[] = $log->message.json_encode($log->context);
        });

        app(RuntimeProviderCredentials::class)->apply();
        Schema::drop('provider_settings');
        app(RuntimeProviderCredentials::class)->apply();

        $this->assertStringNotContainsString($secret, implode("\n", $lines));
        $this->getJson('/api/v1/provider-credentials')->assertUnauthorized();
    }

    public function test_overlay_is_registered_before_platform_config_resolves(): void
    {
        $loaded = array_keys(app()->getLoadedProviders());
        $this->assertLessThan(
            array_search(\App\Providers\AIServiceProvider::class, $loaded, true),
            array_search(\App\Providers\ProviderCredentialsServiceProvider::class, $loaded, true),
        );
        $this->assertFalse(RuntimeProviderCredentials::$platformResolvedBeforeApply);
        $this->assertFalse(app()->resolved(PlatformConfig::class));
        app(PlatformConfig::class);
        $this->assertTrue(app()->resolved(PlatformConfig::class));
    }

    public function test_connection_tests_map_status_codes_and_headers(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(ProviderCredentialService::class);
        foreach (['openai', 'gemini', 'elevenlabs', 'runway', 'luma', 'seedance'] as $slug) {
            $service->setKey($slug, self::KEY, [], $admin);
        }

        $this->replaceHttpFake([
            'https://api.openai.com/v1/models' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([], 401)
                ->push([], 429)
                ->push([], 500),
        ]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')
            ->assertOk()->assertJsonPath('data.result', 'connected');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY));

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')
            ->assertOk()->assertJsonPath('data.result', 'invalid_key');
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')
            ->assertOk()->assertJsonPath('data.result', 'rate_limited');
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')
            ->assertOk()->assertJsonPath('data.result', 'unreachable');

        $this->replaceHttpFake(static function (): void {
            throw new ConnectionException('timeout');
        });
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')
            ->assertOk()->assertJsonPath('data.result', 'unreachable');

        $this->replaceHttpFake(['https://generativelanguage.googleapis.com/v1beta/models' => Http::response(['models' => []], 200)]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/gemini/test')->assertOk();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models'
            && ! str_contains($request->url(), self::KEY)
            && $request->hasHeader('x-goog-api-key', self::KEY));

        $this->replaceHttpFake(['https://api.elevenlabs.io/v1/user' => Http::response(['subscription' => []], 200)]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/elevenlabs/test')
            ->assertOk()->assertJsonPath('data.result', 'connected');
        Http::assertSent(fn ($request): bool => $request->hasHeader('xi-api-key', self::KEY));

        $this->replaceHttpFake(['https://api.dev.runwayml.com/v1/organization' => Http::response(['id' => 'org'], 200)]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/runway/test')
            ->assertOk()->assertJsonPath('data.result', 'connected');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.dev.runwayml.com/v1/organization'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $request->hasHeader('X-Runway-Version', '2024-11-06'));
    }

    public function test_luma_and_seedance_are_not_verifiable_without_a_request(): void
    {
        $admin = User::factory()->admin()->create();
        app(ProviderCredentialService::class)->setKey('luma', self::KEY, [], $admin);
        Http::fake();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/luma/test')
            ->assertOk()->assertJsonPath('data.result', 'not_verifiable');
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/seedance/test')
            ->assertOk()->assertJsonPath('data.result', 'not_verifiable');
        Http::assertNothingSent();
    }

    public function test_provider_test_route_rejects_an_unmapped_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $provider = AiProvider::factory()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/providers/'.$provider->uuid.'/test')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'provider_test_unsupported');
    }

    public function test_credential_validation_rejects_bad_keys_and_unknown_settings(): void
    {
        $admin = User::factory()->admin()->create();
        $url = '/api/v1/provider-credentials/openai';

        $this->actingAs($admin, 'sanctum')->putJson($url, ['api_key' => '   '])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($url, ['api_key' => 'bad key'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($url, ['api_key' => str_repeat('a', 513)])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($url, ['api_key' => '••••1234'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($url, ['api_key' => '********'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($url, ['settings' => ['nope' => 'x']])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/provider-credentials/claude', ['api_key' => self::KEY])->assertStatus(422);
    }

    public function test_credentials_are_admin_only_and_throttled(): void
    {
        $member = User::factory()->create(['role' => 'member']);
        foreach ([
            ['get', '/api/v1/provider-credentials'],
            ['put', '/api/v1/provider-credentials/openai'],
            ['delete', '/api/v1/provider-credentials/openai/key'],
            ['post', '/api/v1/provider-credentials/openai/test'],
        ] as [$method, $url]) {
            $this->actingAs($member, 'sanctum')->json($method, $url, ['api_key' => self::KEY])->assertForbidden();
        }

        $this->assertContains('throttle:10,1', Route::getRoutes()->getByName('api.v1.provider-credentials.update')->gatherMiddleware());
        $this->assertContains('throttle:10,1', Route::getRoutes()->getByName('api.v1.provider-credentials.key.destroy')->gatherMiddleware());
        $this->assertContains('throttle:generation', Route::getRoutes()->getByName('api.v1.provider-credentials.test')->gatherMiddleware());
    }

    public function test_anonymous_and_disabled_admin_are_rejected(): void
    {
        $this->getJson('/api/v1/provider-credentials')->assertUnauthorized();

        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('api')->plainTextToken;
        User::query()->whereKey($admin->id)->update(['status' => 'inactive']);
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/v1/provider-credentials')->assertUnauthorized();
    }

    public function test_plaintext_keys_never_appear_in_responses_logs_or_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $lines = [];
        Log::listen(static function ($log) use (&$lines): void {
            $lines[] = $log->message.json_encode($log->context);
        });
        $this->replaceHttpFake([
            'https://api.openai.com/v1/models' => Http::sequence()
                ->push(['data' => []], 200)
                ->push(['error' => ['message' => 'bad '.self::KEY]], 401),
        ]);

        $saved = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/provider-credentials/openai', [
            'api_key' => self::KEY,
        ])->assertOk()->getContent();
        $ok = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')->assertOk()->getContent();
        $failed = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/provider-credentials/openai/test')->assertOk()->getContent();
        $settings = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/provider-settings')->assertOk()->getContent();

        $blob = $saved.$ok.$failed.$settings.implode("\n", $lines).ActivityLog::query()->get()->toJson();
        $this->assertStringNotContainsString(self::KEY, $blob);
        $this->assertStringContainsString('invalid_key', $failed);
        $this->assertSame('••••1234', ProviderSetting::query()->first()->maskedValue());
    }

    /**
     * Http::fake() merges stubs. A fresh factory makes each script the only stub.
     */
    private function replaceHttpFake(mixed $callback): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }
}
