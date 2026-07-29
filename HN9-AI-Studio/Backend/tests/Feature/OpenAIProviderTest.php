<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Providers\OpenAI\OpenAIClient;
use App\AI\Providers\OpenAI\OpenAIConfig;
use App\AI\Providers\OpenAI\OpenAIModelRegistry;
use App\AI\Providers\OpenAI\OpenAIProvider;
use App\AI\Providers\OpenAI\OpenAIResponseNormalizer;
use App\AI\Providers\OpenAI\OpenAITokenCounter;
use App\AI\Providers\OpenAI\OpenAIUsageCalculator;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Support\ProviderConfigResolver;
use App\Providers\AIServiceProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIProviderTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function provider(array $overrides = []): OpenAIProvider
    {
        $dto = ProviderConfigDTO::fromArray('openai', [...[
            'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'configured-text-model', 'models' => ['configured-text-model', 'configured-image-model'],
            'supports_streaming' => true, 'supports_function_calling' => true,
            'pricing' => ['configured-text-model' => ['input' => 2.0, 'output' => 8.0]],
        ], ...$overrides]);
        $config = OpenAIConfig::fromProviderConfig($dto);
        $usage = new OpenAIUsageCalculator($config);

        return new OpenAIProvider(
            new OpenAIClient($this->app->make(Factory::class), $config),
            new OpenAIModelRegistry($config), $usage, new OpenAIResponseNormalizer($usage), new OpenAITokenCounter, $config,
        );
    }

    public function test_text_generation_normalizes_a_responses_api_payload(): void
    {
        Http::fake(['*/responses' => Http::response([
            'model' => 'configured-text-model', 'output_text' => 'Hello from OpenAI',
            'usage' => ['input_tokens' => 4, 'output_tokens' => 6, 'total_tokens' => 10],
        ])]);

        $response = $this->provider()->generateText(new TextRequest(prompt: 'Hello'));

        $this->assertSame('Hello from OpenAI', $response->text);
        $this->assertSame(4, $response->usage?->promptTokens);
        $this->assertSame(6, $response->usage?->completionTokens);
        $this->assertSame(10, $response->usage?->totalTokens);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses' && $request['model'] === 'configured-text-model');
    }

    public function test_image_generation_normalizes_urls(): void
    {
        Http::fake(['*/images/generations' => Http::response(['data' => [['url' => 'https://cdn.example/image.png']]])]);

        $response = $this->provider()->generateImage(new ImageRequest(prompt: 'A studio image', model: 'configured-image-model'));

        $this->assertSame(['https://cdn.example/image.png'], $response->images);
        $this->assertSame('configured-image-model', $response->model);
    }

    public function test_health_check_verifies_configured_default_model(): void
    {
        Http::fake(['*/models/configured-text-model' => Http::response(['id' => 'configured-text-model'])]);

        $health = $this->provider()->healthCheck();

        $this->assertTrue($health->isOperational());
        $this->assertNotNull($health->latencyMs);
    }

    public function test_token_count_and_cost_estimation_use_configured_model_pricing(): void
    {
        $provider = $this->provider();
        $tokens = $provider->countTokens('12345678');
        $cost = $provider->estimateCost(ProviderRequestDTO::fromRequest(new TextRequest(prompt: '12345678', maxTokens: 10)));

        $this->assertSame(2, $tokens->count);
        $this->assertEqualsWithDelta(0.000084, $cost, 0.000001);
    }

    public function test_client_maps_authentication_and_rate_limit_failures(): void
    {
        Http::fake(['*/responses' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'Hello'));
    }

    public function test_client_maps_rate_limit_failures(): void
    {
        Http::fake(['*/responses' => Http::response(['error' => ['message' => 'slow down']], 429)]);
        $this->expectException(ProviderRateLimitException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'Hello'));
    }

    public function test_configuration_loads_without_hardcoded_models(): void
    {
        config()->set('ai.providers.openai.models', ['future-model']);
        config()->set('ai.providers.openai.api_key', 'test-key');
        config()->set('ai.providers.openai.default_model', 'future-model');

        $config = OpenAIConfig::fromProviderConfig($this->app->make(ProviderConfigResolver::class)->resolve('openai'));

        $this->assertSame(['future-model'], $config->models);
        $this->assertSame('future-model', $config->defaultModel);
    }

    public function test_enabled_provider_registers_through_the_runtime_registry(): void
    {
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'future-model', 'models' => ['future-model'],
        ]);

        (new AIServiceProvider($this->app))->boot();

        $registry = $this->app->make(ProviderRegistryInterface::class);
        $provider = $this->app->make(ProviderFactoryInterface::class)->make('openai');
        $this->assertTrue($registry->has('openai'));
        $this->assertSame('openai', $provider->providerName());
    }
}
