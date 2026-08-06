<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\TextRequest;
use App\AI\Support\Capability;
use App\AI\Support\CircuitState;
use App\Providers\AIServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

/**
 * The intelligence layer driven against the real provider adapters, wired
 * exactly as the service provider wires them in production.
 *
 * The suites above prove routing and resilience with doubles; this one proves
 * the shipped adapters still behave correctly underneath them — that the
 * platform is additive, not a parallel path. Vendor responses are stubbed and
 * stray requests are prevented, so no adapter reaches a network.
 */
class ProviderPlatformIntegrationTest extends TestCase
{
    use InteractsWithProviderPlatform;

    private const OPENAI_RESPONSES = 'https://api.openai.com/v1/responses';

    private const CLAUDE_MESSAGES = 'https://api.anthropic.com/v1/messages';

    /**
     * Register two shipped adapters from configuration, as production does.
     */
    private function bootProviders(): void
    {
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'openai-test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'configured-openai-model', 'models' => ['configured-openai-model'],
            'pricing' => ['configured-openai-model' => ['input' => 10.0, 'output' => 30.0]],
            'priority' => 100,
        ]);

        config()->set('ai.providers.claude', [
            'enabled' => true, 'api_key' => 'claude-test-key', 'base_url' => 'https://api.anthropic.com/v1',
            'version' => '2023-06-01', 'default_model' => 'configured-claude-model',
            'models' => ['configured-claude-model'],
            'pricing' => ['configured-claude-model' => ['input' => 1.0, 'output' => 3.0]],
            'priority' => 90,
        ]);

        (new AIServiceProvider($this->app))->boot();

        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openAiPayload(): array
    {
        return [
            'model' => 'configured-openai-model',
            'output_text' => 'from openai',
            'usage' => ['input_tokens' => 4, 'output_tokens' => 6, 'total_tokens' => 10],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claudePayload(): array
    {
        return [
            'model' => 'configured-claude-model',
            'content' => [['type' => 'text', 'text' => 'from claude']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 3, 'output_tokens' => 5],
        ];
    }

    public function test_the_shipped_adapters_register_and_route_by_capability(): void
    {
        $this->bootProviders();

        $registry = $this->app->make(ProviderRegistryInterface::class);
        $manager = $this->app->make(ProviderManagerInterface::class);

        $this->assertTrue($registry->has('openai'));
        $this->assertTrue($registry->has('claude'));

        // The pre-existing manager API is unchanged by the platform layer.
        $this->assertSame(['openai', 'claude'], $manager->forCapability(Capability::Text));
        $this->assertSame(['openai'], $manager->forCapability(Capability::Image));
    }

    public function test_a_dispatch_reaches_the_highest_priority_adapter(): void
    {
        $this->bootProviders();

        Http::fake([self::OPENAI_RESPONSES => Http::response($this->openAiPayload())]);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('openai', $result->providerKey);
        $this->assertSame('from openai', $result->response->toArray()['text']);
        Http::assertSentCount(1);
    }

    public function test_a_vendor_outage_falls_over_to_the_next_adapter(): void
    {
        $this->bootProviders();
        Sleep::fake();

        Http::fake([
            // A 503 is a typed API failure: retried, then handed on.
            self::OPENAI_RESPONSES => Http::response(['error' => ['message' => 'overloaded']], 503),
            self::CLAUDE_MESSAGES => Http::response($this->claudePayload()),
        ]);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('claude', $result->providerKey);
        $this->assertSame('from claude', $result->response->toArray()['text']);
        $this->assertSame(1, $result->fallbacks);
        $this->assertGreaterThan(0, $result->retries);

        $this->assertSame(3, $this->metrics()->forProvider('openai')->failures);
        $this->assertSame(1, $this->metrics()->forProvider('claude')->successes);
    }

    public function test_a_vendor_credential_failure_is_not_retried_but_is_handed_on(): void
    {
        $this->bootProviders();
        Sleep::fake();

        Http::fake([
            self::OPENAI_RESPONSES => Http::response(['error' => ['message' => 'bad key']], 401),
            self::CLAUDE_MESSAGES => Http::response($this->claudePayload()),
        ]);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('claude', $result->providerKey);
        $this->assertSame(1, $this->metrics()->forProvider('openai')->requests, 'no retry on a credential failure');
    }

    public function test_repeated_vendor_failures_open_the_adapter_circuit(): void
    {
        $this->bootProviders();
        Sleep::fake();

        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.enabled' => false,
            'ai.circuit_breaker.failure_threshold' => 2,
            /*
             * Health demotion would move OpenAI behind Claude after its very
             * first failure, so it would never be called a second time and its
             * circuit would never reach the threshold. Disabled here to isolate
             * the breaker; the interaction itself is covered below.
             */
            'ai.routing.health.enabled' => false,
        ]);

        Http::fake([
            self::OPENAI_RESPONSES => Http::response(['error' => ['message' => 'overloaded']], 503),
            self::CLAUDE_MESSAGES => Http::response($this->claudePayload()),
        ]);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'one'));
        $this->dispatcher()->dispatch(new TextRequest(prompt: 'two'));

        $this->assertSame(CircuitState::Open, $this->breaker()->state('openai'));

        Http::fake([self::CLAUDE_MESSAGES => Http::response($this->claudePayload())]);

        // With the circuit open the adapter is not called at all, so the
        // OpenAI route being unstubbed cannot produce a stray request.
        $third = $this->dispatcher()->dispatch(new TextRequest(prompt: 'three'));

        $this->assertSame('claude', $third->providerKey);
    }

    public function test_one_vendor_failure_demotes_the_adapter_for_the_next_request(): void
    {
        $this->bootProviders();
        Sleep::fake();

        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        Http::fake([
            self::OPENAI_RESPONSES => Http::response(['error' => ['message' => 'overloaded']], 503),
            self::CLAUDE_MESSAGES => Http::response($this->claudePayload()),
        ]);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'one'));

        // Observed health, not the circuit: one failure is enough to rank the
        // adapter behind its healthy peer on the very next request.
        $second = $this->dispatcher()->dispatch(new TextRequest(prompt: 'two'));

        $this->assertSame('claude', $second->providerKey);
        $this->assertSame(0, $second->fallbacks, 'Claude was the first choice, not a fallback');
        $this->assertSame(1, $this->metrics()->forProvider('openai')->requests);
    }

    public function test_cost_routing_prices_the_real_adapters(): void
    {
        $this->bootProviders();

        $this->configurePlatform([
            'ai.cost.enabled' => true,
            'ai.routing.strategy' => 'cheapest',
        ]);

        Http::fake([self::CLAUDE_MESSAGES => Http::response($this->claudePayload())]);

        // Claude is configured a tenth of OpenAI's rate, so it wins on price
        // despite OpenAI's higher priority — and both estimates are the
        // adapters' own, not the platform's.
        $result = $this->dispatcher()->dispatch(
            new TextRequest(prompt: 'a reasonably long prompt to price', maxTokens: 500),
        );

        $this->assertSame('claude', $result->providerKey);
        $this->assertGreaterThan(0.0, $result->estimatedCost);
    }

    public function test_a_caller_may_pin_a_single_adapter(): void
    {
        $this->bootProviders();

        Http::fake([self::CLAUDE_MESSAGES => Http::response($this->claudePayload())]);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'), DispatchOptions::only('claude'));

        $this->assertSame('claude', $result->providerKey);
        Http::assertSentCount(1);
    }

    public function test_the_platform_never_reaches_an_unstubbed_vendor_route(): void
    {
        $this->bootProviders();

        Http::fake([self::OPENAI_RESPONSES => Http::response($this->openAiPayload())]);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        Http::assertSent(fn ($request): bool => $request->url() === self::OPENAI_RESPONSES);
        Http::assertSentCount(1);
    }
}
