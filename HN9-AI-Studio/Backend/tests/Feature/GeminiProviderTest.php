<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Providers\Gemini\GeminiClient;
use App\AI\Providers\Gemini\GeminiConfig;
use App\AI\Providers\Gemini\GeminiModelRegistry;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Providers\Gemini\GeminiResponseNormalizer;
use App\AI\Providers\Gemini\GeminiTokenCounter;
use App\AI\Providers\Gemini\GeminiUsageCalculator;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Support\Capability;
use App\AI\Support\ProviderConfigResolver;
use App\Providers\AIServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    private const TEXT_URL = 'https://generativelanguage.googleapis.com/v1beta/models/configured-text-model:generateContent';

    private const IMAGE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/configured-image-model:generateContent';

    private const COUNT_URL = 'https://generativelanguage.googleapis.com/v1beta/models/configured-text-model:countTokens';

    private const MODEL_URL = 'https://generativelanguage.googleapis.com/v1beta/models/configured-text-model';

    /**
     * Builds the adapter exactly as the service provider does, from a
     * configuration array — nothing about the vendor is hardcoded in the adapter.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function provider(array $overrides = []): GeminiProvider
    {
        $config = $this->config($overrides);
        $client = new GeminiClient($this->app->make(Factory::class), $config);
        $usage = new GeminiUsageCalculator($config);
        $normalizer = new GeminiResponseNormalizer($usage);

        return new GeminiProvider(
            $client,
            new GeminiModelRegistry($config),
            $usage,
            $normalizer,
            new GeminiTokenCounter($client, $normalizer, $config),
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): GeminiConfig
    {
        return GeminiConfig::fromProviderConfig(ProviderConfigDTO::fromArray('gemini', [...$this->settings(), ...$overrides]));
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'api_key' => 'test-key',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'version' => 'v1beta',
            'default_model' => 'configured-text-model',
            'models' => ['configured-text-model'],
            'image_models' => ['configured-image-model'],
            'image_default_model' => 'configured-image-model',
            'image_response_modalities' => ['IMAGE'],
            'remote_token_counting' => false,
            'supports_streaming' => true,
            'supports_function_calling' => true,
            'pricing' => ['configured-text-model' => ['input' => 2.0, 'output' => 8.0]],
        ];
    }

    public function test_text_generation_is_normalized_into_the_shared_text_response(): void
    {
        Http::fake([self::TEXT_URL => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Hello from '], ['text' => 'Gemini']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 4, 'candidatesTokenCount' => 6, 'thoughtsTokenCount' => 2, 'totalTokenCount' => 12],
            'modelVersion' => 'configured-text-model-001',
        ])]);

        $response = $this->provider()->generateText(new TextRequest(prompt: 'Hello'));

        $this->assertSame('Hello from Gemini', $response->text);
        $this->assertSame('configured-text-model-001', $response->model);
        $this->assertSame('STOP', $response->finishReason);
        $this->assertSame(4, $response->usage?->promptTokens);
        // Thinking tokens bill at the output rate, so they count as completion tokens.
        $this->assertSame(8, $response->usage?->completionTokens);
        $this->assertSame(12, $response->usage?->totalTokens);
        $this->assertNotNull($response->usage?->executionTimeMs);
        $this->assertEqualsWithDelta(0.000072, $response->usage?->cost ?? 0.0, 0.0000001);
    }

    public function test_text_payload_maps_roles_system_instruction_and_generation_config(): void
    {
        Http::fake([self::TEXT_URL => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])]);

        $this->provider()->generateText(new TextRequest(
            prompt: 'ignored when messages are supplied',
            messages: [
                ['role' => 'system', 'content' => 'Be terse.'],
                ['role' => 'user', 'content' => 'Ping'],
                ['role' => 'assistant', 'content' => 'Pong'],
            ],
            maxTokens: 128,
            temperature: 0.4,
            topP: 0.9,
            system: 'Studio voice.',
            stop: ['END'],
        ));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::TEXT_URL
                && $body['contents'] === [
                    ['role' => 'user', 'parts' => [['text' => 'Ping']]],
                    ['role' => 'model', 'parts' => [['text' => 'Pong']]],
                ]
                && $body['systemInstruction'] === ['parts' => [['text' => 'Studio voice.'], ['text' => 'Be terse.']]]
                && $body['generationConfig'] === [
                    'maxOutputTokens' => 128,
                    'temperature' => 0.4,
                    'topP' => 0.9,
                    'stopSequences' => ['END'],
                ];
        });
    }

    public function test_image_generation_returns_data_uris_for_inline_image_parts(): void
    {
        Http::fake([self::IMAGE_URL => Http::response([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'Here you go'],
                    ['inlineData' => ['mimeType' => 'image/webp', 'data' => 'QUJD']],
                ]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 5, 'totalTokenCount' => 5],
        ])]);

        $response = $this->provider()->generateImage(new ImageRequest(prompt: 'A studio image'));

        $this->assertSame(['data:image/webp;base64,QUJD'], $response->images);
        $this->assertSame(1, $response->count());
        $this->assertSame('configured-image-model', $response->model);
        $this->assertSame(5, $response->usage?->promptTokens);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::IMAGE_URL
            && $request->data()['generationConfig'] === ['responseModalities' => ['IMAGE']]);
    }

    public function test_image_generation_rejects_a_model_that_is_not_image_capable(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);

        $this->provider()->generateImage(new ImageRequest(prompt: 'x', model: 'configured-text-model'));
    }

    public function test_health_check_probes_the_configured_default_model(): void
    {
        Http::fake([self::MODEL_URL => Http::response(['name' => 'models/configured-text-model'])]);

        $health = $this->provider()->healthCheck();

        $this->assertTrue($health->isOperational());
        $this->assertNotNull($health->latencyMs);
        $this->assertNotNull($health->checkedAt);
        $this->assertSame('gemini', $health->key);
        $this->assertSame('v1beta', $health->details['api_version']);
        $this->assertSame('configured-text-model', $health->details['default_model']);
        $this->assertTrue($health->details['model_verified']);
        $this->assertSame(['configured-image-model'], $health->details['image_models']);
    }

    public function test_health_check_reports_unavailable_when_the_api_fails(): void
    {
        Http::fake([self::MODEL_URL => Http::response(['error' => ['message' => 'backend down']], 503)]);

        $health = $this->provider()->healthCheck();

        $this->assertFalse($health->isOperational());
        $this->assertSame('backend down', $health->message);
    }

    public function test_remote_token_counting_uses_the_vendor_tokenizer(): void
    {
        Http::fake([self::COUNT_URL => Http::response(['totalTokens' => 42])]);

        $tokens = $this->provider(['remote_token_counting' => true])->countTokens('Some prompt text');

        $this->assertSame(42, $tokens->count);
        $this->assertSame('configured-text-model', $tokens->model);
    }

    public function test_token_counting_uses_the_local_estimate_when_remote_counting_is_disabled(): void
    {
        Http::fake();

        $this->assertSame(2, $this->provider()->countTokens('12345678')->count);
        Http::assertNothingSent();
    }

    public function test_token_counting_degrades_to_the_local_estimate_when_the_tokenizer_fails(): void
    {
        Http::fake([self::COUNT_URL => Http::response(['error' => ['message' => 'unavailable']], 503)]);

        $this->assertSame(2, $this->provider(['remote_token_counting' => true])->countTokens('12345678')->count);
    }

    public function test_cost_estimation_prices_tokens_with_the_configured_rates(): void
    {
        $cost = $this->provider()->estimateCost(
            ProviderRequestDTO::fromRequest(new TextRequest(prompt: '12345678', maxTokens: 10)),
        );

        // 2 prompt tokens at 2.00/M + 10 output tokens at 8.00/M.
        $this->assertEqualsWithDelta(0.000084, $cost, 0.0000001);
    }

    public function test_cost_estimation_resolves_image_models_for_image_requests(): void
    {
        $cost = $this->provider()->estimateCost(
            ProviderRequestDTO::fromRequest(new ImageRequest(prompt: '12345678')),
        );

        // The image model carries no configured price, so the estimate is zero.
        $this->assertSame(0.0, $cost);
    }

    public function test_capability_flags_and_models_come_from_configuration(): void
    {
        $provider = $this->provider();

        $this->assertSame('gemini', $provider->providerName());
        $this->assertSame(GeminiProvider::VERSION, $provider->providerVersion());
        $this->assertTrue($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
        $this->assertSame(['configured-text-model', 'configured-image-model'], $provider->supportedModels());
        $this->assertFalse($this->provider(['supports_streaming' => false])->supportsStreaming());
    }

    public function test_video_and_voice_report_the_unsupported_capability(): void
    {
        $provider = $this->provider();

        try {
            $provider->generateVideo(new VideoRequest(prompt: 'x'));
            $this->fail('Expected an unsupported-capability exception for video.');
        } catch (UnsupportedCapabilityException $exception) {
            $this->assertSame(Capability::Video->value, $exception->context()['capability'] ?? null);
        }

        $this->expectException(UnsupportedCapabilityException::class);
        $provider->generateVoice(new VoiceRequest(input: 'x'));
    }

    public function test_http_401_is_mapped_to_a_typed_authentication_exception(): void
    {
        Http::fake([self::TEXT_URL => Http::response([], 401)]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_an_invalid_api_key_reported_as_http_400_is_mapped_to_authentication(): void
    {
        // Google reports a bad credential as 400 INVALID_ARGUMENT, not 401.
        Http::fake([self::TEXT_URL => Http::response([
            'error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid. Please pass a valid API key.'],
        ], 400)]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_permission_denied_is_mapped_to_authentication(): void
    {
        Http::fake([self::TEXT_URL => Http::response([
            'error' => ['code' => 400, 'status' => 'PERMISSION_DENIED', 'message' => 'caller lacks permission'],
        ], 400)]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_rate_limiting_is_mapped_to_a_typed_exception(): void
    {
        Http::fake([self::TEXT_URL => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']], 429)]);

        $this->expectException(ProviderRateLimitException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_api_failures_are_mapped_to_a_typed_exception_with_the_vendor_message(): void
    {
        Http::fake([self::TEXT_URL => Http::response(['error' => ['status' => 'INTERNAL', 'message' => 'internal error']], 500)]);

        try {
            $this->provider()->generateText(new TextRequest(prompt: 'x'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('internal error', $exception->getMessage());
            $this->assertSame('gemini', $exception->context()['provider'] ?? null);
        }
    }

    public function test_a_timeout_is_mapped_to_a_typed_timeout_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'));

        $this->expectException(ProviderTimeoutException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_a_connection_failure_is_mapped_to_a_typed_network_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('Could not resolve host: generativelanguage.googleapis.com'));

        $this->expectException(ProviderNetworkException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_an_unparseable_response_is_mapped_to_a_typed_exception(): void
    {
        Http::fake([self::TEXT_URL => Http::response(['candidates' => [['content' => ['parts' => [['functionCall' => []]]]]]])]);

        $this->expectException(ProviderApiException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_missing_credentials_or_endpoint_raise_a_configuration_error(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->config(['api_key' => null]);
    }

    public function test_a_missing_api_version_raises_a_configuration_error(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->config(['version' => '']);
    }

    public function test_an_unconfigured_model_is_rejected(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x', model: 'unlisted-model'));
    }

    public function test_configuration_is_loaded_dynamically_from_config_and_the_environment(): void
    {
        config()->set('ai.providers.gemini', [
            'api_key' => 'test-key',
            'base_url' => 'https://generativelanguage.googleapis.com/',
            'version' => 'v9-future',
            'default_model' => 'gemini-future-pro',
            'models' => ['gemini-future-pro', ' gemini-future-flash '],
            'timeout' => 45,
            'max_retries' => 5,
        ]);

        $config = GeminiConfig::fromProviderConfig($this->app->make(ProviderConfigResolver::class)->resolve('gemini'));

        $this->assertSame(['gemini-future-pro', 'gemini-future-flash'], $config->models);
        $this->assertSame('gemini-future-pro', $config->defaultModel);
        $this->assertSame('https://generativelanguage.googleapis.com/v9-future', $config->endpoint());
        $this->assertSame(45, $config->timeout);
        $this->assertSame(5, $config->maxRetries);
    }

    public function test_gemini_registers_through_the_provider_registry(): void
    {
        config()->set('ai.providers.gemini', [...$this->settings(), 'enabled' => true, 'priority' => 80]);

        (new AIServiceProvider($this->app))->boot();

        $registry = $this->app->make(ProviderRegistryInterface::class);
        $manager = $this->app->make(ProviderManagerInterface::class);

        $this->assertTrue($registry->has('gemini'));
        $this->assertSame('gemini', $this->app->make(ProviderFactoryInterface::class)->make('gemini')->providerName());
        $this->assertContains('gemini', $manager->forCapability(Capability::Text));
        $this->assertContains('gemini', $manager->forCapability(Capability::Image));
        $this->assertSame(['configured-text-model', 'configured-image-model'], $manager->capabilities('gemini')->models);
    }

    public function test_image_capability_is_not_declared_without_configured_image_models(): void
    {
        config()->set('ai.providers.gemini', [...$this->settings(), 'enabled' => true, 'image_models' => [], 'image_default_model' => null]);

        (new AIServiceProvider($this->app))->boot();

        $manager = $this->app->make(ProviderManagerInterface::class);

        $this->assertContains('gemini', $manager->forCapability(Capability::Text));
        $this->assertNotContains('gemini', $manager->forCapability(Capability::Image));
    }

    public function test_gemini_registers_alongside_openai_and_claude_without_disturbing_them(): void
    {
        config()->set('ai.providers.gemini', [...$this->settings(), 'enabled' => true]);
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'future-model', 'models' => ['future-model'],
        ]);
        config()->set('ai.providers.claude', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.example/v1',
            'version' => 'test-version', 'default_model' => 'future-claude', 'models' => ['future-claude'],
        ]);

        (new AIServiceProvider($this->app))->boot();

        $manager = $this->app->make(ProviderManagerInterface::class);
        $factory = $this->app->make(ProviderFactoryInterface::class);

        foreach (['gemini', 'openai', 'claude'] as $key) {
            $this->assertTrue($manager->has($key));
            $this->assertSame($key, $factory->make($key)->providerName());
        }

        // Priority ordering is configuration-driven: OpenAI 100, Claude 90, Gemini 80.
        $this->assertSame(['openai', 'claude', 'gemini'], $manager->forCapability(Capability::Text));
    }
}
