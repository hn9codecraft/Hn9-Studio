<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Config\PlatformConfig;
use App\AI\Exceptions\BudgetExceededException;
use App\AI\Exceptions\CircuitOpenException;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Routing\RoutingContext;
use App\AI\Support\Capability;
use App\AI\Support\CircuitState;
use App\AI\Support\CostStrategy;
use App\AI\Support\HealthStatus;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\Support\RecordingProvider;
use Tests\TestCase;

/**
 * Intelligent provider selection: capability, priority, health, configuration,
 * availability, cost and caller preference.
 *
 * Routing plans only — nothing here dispatches, so nothing here may reach a
 * network. The base test case prevents stray requests for the whole suite.
 */
class ProviderRoutingTest extends TestCase
{
    use InteractsWithProviderPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic ordering by default; individual tests opt into scoring.
        $this->configurePlatform(['ai.routing.strategy' => 'priority']);
    }

    private function plan(TextRequest|ImageRequest|VoiceRequest $request, ?DispatchOptions $options = null): array
    {
        $context = RoutingContext::for(
            $request,
            $options ?? DispatchOptions::make(),
            $this->app->make(PlatformConfig::class),
        );

        return $this->router()->route($context)->keys();
    }

    // ------------------------------------------------------- capability --

    public function test_a_capability_only_routes_to_providers_that_declare_it(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'), priority: 10);
        $this->registerProvider('painter', RecordingProvider::succeeding('painter'), priority: 90, text: false, image: true);
        $this->registerProvider('speaker', RecordingProvider::succeeding('speaker'), priority: 80, text: false, voice: true);

        $this->assertSame(['writer'], $this->plan(new TextRequest(prompt: 'hi')));
        $this->assertSame(['painter'], $this->plan(new ImageRequest(prompt: 'hi')));
        $this->assertSame(['speaker'], $this->plan(new VoiceRequest(input: 'hi')));
    }

    public function test_an_unserved_capability_raises_rather_than_guessing(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $this->expectException(NoProviderAvailableException::class);

        $this->plan(new ImageRequest(prompt: 'hi'));
    }

    public function test_a_request_may_demand_additional_capabilities(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'), priority: 10);

        $this->expectException(NoProviderAvailableException::class);

        $this->plan(
            new TextRequest(prompt: 'hi'),
            new DispatchOptions(requiredCapabilities: [Capability::FunctionCalling]),
        );
    }

    // --------------------------------------------------------- priority --

    public function test_priority_orders_the_plan(): void
    {
        $this->registerProvider('low', RecordingProvider::succeeding('low'), priority: 10);
        $this->registerProvider('high', RecordingProvider::succeeding('high'), priority: 90);
        $this->registerProvider('mid', RecordingProvider::succeeding('mid'), priority: 50);

        $this->assertSame(['high', 'mid', 'low'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    // ------------------------------------------------------ preference --

    public function test_a_caller_preference_outranks_the_scored_order(): void
    {
        $this->registerProvider('low', RecordingProvider::succeeding('low'), priority: 10);
        $this->registerProvider('high', RecordingProvider::succeeding('high'), priority: 90);

        $plan = $this->plan(new TextRequest(prompt: 'hi'), DispatchOptions::make()->withPreferred('low'));

        $this->assertSame(['low', 'high'], $plan);
    }

    public function test_an_excluded_provider_never_appears(): void
    {
        $this->registerProvider('low', RecordingProvider::succeeding('low'), priority: 10);
        $this->registerProvider('high', RecordingProvider::succeeding('high'), priority: 90);

        $this->assertSame(['low'], $this->plan(new TextRequest(prompt: 'hi'), DispatchOptions::make()->without('high')));
    }

    // -------------------------------------------------------- the model --

    public function test_a_pinned_model_removes_providers_that_do_not_publish_it(): void
    {
        $this->registerProvider('a', RecordingProvider::succeeding('a'), priority: 90, models: ['model-a']);
        $this->registerProvider('b', RecordingProvider::succeeding('b'), priority: 10, models: ['model-b']);

        $this->assertSame(['b'], $this->plan(new TextRequest(prompt: 'hi', model: 'model-b')));
    }

    public function test_a_provider_publishing_no_catalogue_is_treated_as_unconstrained(): void
    {
        $this->registerProvider('open', RecordingProvider::succeeding('open'), priority: 10);

        $this->assertSame(['open'], $this->plan(new TextRequest(prompt: 'hi', model: 'anything')));
    }

    // ----------------------------------------------------------- health --

    public function test_a_degraded_provider_is_demoted_but_still_routable(): void
    {
        $this->registerProvider('shaky', RecordingProvider::succeeding('shaky'), priority: 90);
        $this->registerProvider('steady', RecordingProvider::succeeding('steady'), priority: 10);

        $this->healthTracker()->recordFailure('shaky', ProviderTimeoutException::forProvider('shaky'));

        $this->assertSame(HealthStatus::Degraded, $this->healthTracker()->status('shaky'));
        $this->assertSame(['steady', 'shaky'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_an_unavailable_provider_is_removed_from_routing(): void
    {
        $this->registerProvider('dead', RecordingProvider::succeeding('dead'), priority: 90);
        $this->registerProvider('alive', RecordingProvider::succeeding('alive'), priority: 10);

        // The configured threshold is three consecutive failures.
        for ($i = 0; $i < 3; $i++) {
            $this->healthTracker()->recordFailure('dead', ProviderTimeoutException::forProvider('dead'));
        }

        $this->assertSame(HealthStatus::Unavailable, $this->healthTracker()->status('dead'));
        $this->assertSame(['alive'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_a_success_restores_a_degraded_provider(): void
    {
        $this->registerProvider('shaky', RecordingProvider::succeeding('shaky'), priority: 90);
        $this->registerProvider('steady', RecordingProvider::succeeding('steady'), priority: 10);

        $this->healthTracker()->recordFailure('shaky', ProviderTimeoutException::forProvider('shaky'));
        $this->healthTracker()->recordSuccess('shaky', 12);

        $this->assertSame(HealthStatus::Healthy, $this->healthTracker()->status('shaky'));
        $this->assertSame(['shaky', 'steady'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_health_routing_can_be_switched_off_entirely(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.routing.health.enabled' => false,
        ]);

        $this->registerProvider('dead', RecordingProvider::succeeding('dead'), priority: 90);
        $this->registerProvider('alive', RecordingProvider::succeeding('alive'), priority: 10);

        for ($i = 0; $i < 5; $i++) {
            $this->healthTracker()->recordFailure('dead', ProviderTimeoutException::forProvider('dead'));
        }

        $this->assertSame(['dead', 'alive'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    // ---------------------------------------------------------- circuit --

    public function test_an_open_circuit_removes_a_provider_from_the_plan(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.circuit_breaker.failure_threshold' => 2,
            // Isolate the circuit's effect from the health tracker's.
            'ai.routing.health.enabled' => false,
        ]);

        $this->registerProvider('tripped', RecordingProvider::succeeding('tripped'), priority: 90);
        $this->registerProvider('healthy', RecordingProvider::succeeding('healthy'), priority: 10);

        $this->breaker()->recordFailure('tripped');
        $this->breaker()->recordFailure('tripped');

        $this->assertSame(CircuitState::Open, $this->breaker()->state('tripped'));
        $this->assertSame(['healthy'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_every_circuit_being_open_is_reported_as_breaking_not_as_missing(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.circuit_breaker.failure_threshold' => 1,
            'ai.circuit_breaker.recovery_timeout' => 45,
            'ai.routing.health.enabled' => false,
        ]);

        $this->registerProvider('only', RecordingProvider::succeeding('only'));
        $this->breaker()->recordFailure('only');

        try {
            $this->plan(new TextRequest(prompt: 'hi'));
            $this->fail('An entirely open field should not have produced a plan.');
        } catch (CircuitOpenException $exception) {
            $this->assertSame('ai_circuit_open', $exception->errorCode());
            $this->assertSame(503, $exception->statusCode());
            $this->assertSame(['only' => 45], $exception->context()['providers']);
            $this->assertSame(45, $exception->context()['retry_after']);
        }
    }

    // ------------------------------------------------------------- cost --

    public function test_the_cheapest_strategy_prefers_the_lower_estimate(): void
    {
        $this->configurePlatform([
            'ai.cost.enabled' => true,
            'ai.routing.strategy' => 'cheapest',
        ]);

        $this->registerProvider('premium', RecordingProvider::succeeding('premium', cost: 5.0), priority: 90);
        $this->registerProvider('budget', RecordingProvider::succeeding('budget', cost: 0.5), priority: 10);

        $this->assertSame(['budget', 'premium'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_the_quality_strategy_ignores_price(): void
    {
        $this->configurePlatform([
            'ai.cost.enabled' => true,
            'ai.routing.strategy' => 'quality',
        ]);

        $this->registerProvider('premium', RecordingProvider::succeeding('premium', cost: 5.0), priority: 90);
        $this->registerProvider('budget', RecordingProvider::succeeding('budget', cost: 0.5), priority: 10);

        $this->assertSame(['premium', 'budget'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_a_cost_preference_selects_the_strategy_without_naming_it(): void
    {
        $this->configurePlatform(['ai.cost.enabled' => true]);

        $this->registerProvider('premium', RecordingProvider::succeeding('premium', cost: 5.0), priority: 90);
        $this->registerProvider('budget', RecordingProvider::succeeding('budget', cost: 0.5), priority: 10);

        $plan = $this->plan(
            new TextRequest(prompt: 'hi'),
            DispatchOptions::make()->withStrategy(CostStrategy::Cheapest->strategyKey()),
        );

        $this->assertSame(['budget', 'premium'], $plan);
    }

    public function test_a_budget_removes_candidates_that_would_exceed_it(): void
    {
        $this->configurePlatform(['ai.cost.enabled' => true, 'ai.routing.strategy' => 'priority']);

        $this->registerProvider('premium', RecordingProvider::succeeding('premium', cost: 5.0), priority: 90);
        $this->registerProvider('budget', RecordingProvider::succeeding('budget', cost: 0.5), priority: 10);

        $plan = $this->plan(new TextRequest(prompt: 'hi'), DispatchOptions::make()->withBudget(1.0));

        $this->assertSame(['budget'], $plan);
    }

    public function test_a_budget_no_provider_can_meet_is_its_own_failure(): void
    {
        $this->configurePlatform(['ai.cost.enabled' => true]);

        $this->registerProvider('premium', RecordingProvider::succeeding('premium', cost: 5.0), priority: 90);

        $this->expectException(BudgetExceededException::class);

        $this->plan(new TextRequest(prompt: 'hi'), DispatchOptions::make()->withBudget(1.0));
    }

    public function test_cost_is_not_estimated_while_optimisation_is_off(): void
    {
        $this->registerProvider('premium', RecordingProvider::succeeding('premium', cost: 5.0), priority: 90);

        $context = RoutingContext::for(
            new TextRequest(prompt: 'hi'),
            DispatchOptions::make(),
            $this->app->make(PlatformConfig::class),
        );

        $this->assertFalse($context->estimateCost);
        $this->assertNull($this->router()->route($context)->candidates[0]->estimatedCost);
    }

    // --------------------------------------------------------- fallback --

    public function test_a_configured_chain_dictates_the_order(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.routing.fallback.chains.text' => ['third', 'first'],
        ]);

        $this->registerProvider('first', RecordingProvider::succeeding('first'), priority: 10);
        $this->registerProvider('second', RecordingProvider::succeeding('second'), priority: 99);
        $this->registerProvider('third', RecordingProvider::succeeding('third'), priority: 1);

        // Chained providers lead in chain order; the rest follow, scored.
        $this->assertSame(['third', 'first', 'second'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_restrict_mode_limits_the_candidate_set_and_leaves_ordering_to_scoring(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.routing.fallback.mode' => 'restrict',
            'ai.routing.fallback.chains.text' => ['third', 'first'],
        ]);

        $this->registerProvider('first', RecordingProvider::succeeding('first'), priority: 10);
        $this->registerProvider('second', RecordingProvider::succeeding('second'), priority: 99);
        $this->registerProvider('third', RecordingProvider::succeeding('third'), priority: 1);

        $this->assertSame(['first', 'third'], $this->plan(new TextRequest(prompt: 'hi')));
    }

    public function test_the_plan_records_why_each_provider_was_rejected(): void
    {
        $this->registerProvider('kept', RecordingProvider::succeeding('kept'), priority: 10);
        $this->registerProvider('dropped', RecordingProvider::succeeding('dropped'), priority: 90);

        $context = RoutingContext::for(
            new TextRequest(prompt: 'hi'),
            DispatchOptions::make()->without('dropped'),
            $this->app->make(PlatformConfig::class),
        );

        $this->assertSame(['dropped' => 'excluded_by_caller'], $this->router()->route($context)->rejected);
    }

    public function test_routing_reaches_no_network(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $this->plan(new TextRequest(prompt: 'hi'));

        Http::assertNothingSent();
    }
}
