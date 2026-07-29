<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Providers\Claude\ClaudeClient;
use App\AI\Providers\Claude\ClaudeConfig;
use App\AI\Providers\Claude\ClaudeModelRegistry;
use App\AI\Providers\Claude\ClaudeProvider;
use App\AI\Providers\Claude\ClaudeResponseNormalizer;
use App\AI\Providers\Claude\ClaudeTokenCounter;
use App\AI\Providers\Claude\ClaudeUsageCalculator;
use App\AI\Requests\TextRequest;
use App\Providers\AIServiceProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeProviderTest extends TestCase
{
    private function provider(): ClaudeProvider
    {
        $config = ClaudeConfig::fromProviderConfig(ProviderConfigDTO::fromArray('claude', ['api_key' => 'test-key', 'base_url' => 'https://api.example/v1', 'version' => 'test-version', 'default_model' => 'future-claude', 'models' => ['future-claude'], 'pricing' => ['future-claude' => ['input' => 2.0, 'output' => 8.0]]]));
        $usage = new ClaudeUsageCalculator($config);

        return new ClaudeProvider(new ClaudeClient($this->app->make(Factory::class), $config), new ClaudeModelRegistry($config), $usage, new ClaudeResponseNormalizer($usage), new ClaudeTokenCounter, $config);
    }

    public function test_text_response_and_usage_are_normalized(): void
    {
        Http::fake(['*/messages' => Http::response(['model' => 'future-claude', 'content' => [['type' => 'text', 'text' => 'Claude text']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 3, 'output_tokens' => 4]])]);
        $response = $this->provider()->generateText(new TextRequest(prompt: 'Hello'));
        $this->assertSame('Claude text', $response->text);
        $this->assertSame(7, $response->usage?->totalTokens);
    }

    public function test_health_token_cost_and_typed_auth_error(): void
    {
        Http::fake(['*/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
        $provider = $this->provider();
        $this->assertTrue($provider->healthCheck()->isOperational());
        $this->assertSame(2, $provider->countTokens('12345678')->count);
        $this->assertEqualsWithDelta(0.000084, $provider->estimateCost(ProviderRequestDTO::fromRequest(new TextRequest(prompt: '12345678', maxTokens: 10))), 0.000001);
    }

    public function test_authentication_failure_is_mapped_to_a_typed_exception(): void
    {
        Http::fake(['*/messages' => Http::response([], 401)]);
        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_enabled_claude_registers_without_affecting_openai_contracts(): void
    {
        config()->set('ai.providers.claude', ['enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.example/v1', 'version' => 'test-version', 'default_model' => 'future-claude', 'models' => ['future-claude']]);
        (new AIServiceProvider($this->app))->boot();
        $this->assertTrue($this->app->make(ProviderRegistryInterface::class)->has('claude'));
        $this->assertSame('claude', $this->app->make(ProviderFactoryInterface::class)->make('claude')->providerName());
    }
}
