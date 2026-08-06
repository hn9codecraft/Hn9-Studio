<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Cache\ProviderInstanceCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\HealthManagerInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\Exceptions\AllProvidersFailedException;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\VoiceResponse;
use App\AI\Support\Capability;
use App\AI\Support\CircuitState;
use App\AI\Support\HealthStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\Support\RecordingProvider;
use Tests\TestCase;

/**
 * End-to-end behaviour of the resilient dispatcher: retry, fallback, circuit
 * breaking, timeout, metrics and the caches — composed as one request path.
 */
class ProviderDispatcherTest extends TestCase
{
    use InteractsWithProviderPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 1,
        ]);
    }

    // ------------------------------------------------------- happy path --

    public function test_a_request_is_served_by_the_highest_ranked_provider(): void
    {
        $primary = $this->registerProvider('primary', RecordingProvider::succeeding('primary'), priority: 90);
        $secondary = $this->registerProvider('secondary', RecordingProvider::succeeding('secondary'), priority: 10);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('primary', $result->providerKey);
        $this->assertInstanceOf(TextResponse::class, $result->response);
        $this->assertSame('primary:hello', $result->response->text);
        $this->assertSame(0, $result->retries);
        $this->assertSame(0, $result->fallbacks);
        $this->assertFalse($result->usedFallback());
        $this->assertSame(1, $primary->calls);
        $this->assertSame(0, $secondary->calls);
    }

    public function test_the_typed_helpers_return_the_modality_response(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));
        $this->registerProvider('painter', RecordingProvider::succeeding('painter'), text: false, image: true);
        $this->registerProvider('speaker', RecordingProvider::succeeding('speaker'), text: false, voice: true);

        $dispatcher = $this->dispatcher();

        $this->assertInstanceOf(TextResponse::class, $dispatcher->text(new TextRequest(prompt: 'a')));
        $this->assertInstanceOf(ImageResponse::class, $dispatcher->image(new ImageRequest(prompt: 'a')));
        $this->assertInstanceOf(VoiceResponse::class, $dispatcher->voice(new VoiceRequest(input: 'a')));
    }

    public function test_a_modality_no_provider_serves_never_reaches_a_provider(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $this->expectException(NoProviderAvailableException::class);

        $this->dispatcher()->video(new VideoRequest(prompt: 'a'));
    }

    // ------------------------------------------------------------ retry --

    public function test_a_provider_is_retried_before_the_request_moves_on(): void
    {
        $flaky = $this->registerProvider(
            'flaky',
            RecordingProvider::recoveringAfter('flaky', 2, ProviderTimeoutException::forProvider('flaky')),
            priority: 90,
        );

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('flaky', $result->providerKey);
        $this->assertSame(3, $flaky->calls);
        $this->assertSame(2, $result->retries);
        $this->assertSame(0, $result->fallbacks);
    }

    public function test_a_non_retryable_failure_moves_straight_to_the_next_provider(): void
    {
        $broken = $this->registerProvider(
            'broken',
            RecordingProvider::failing('broken', ProviderAuthenticationException::forProvider('broken')),
            priority: 90,
        );
        $backup = $this->registerProvider('backup', RecordingProvider::succeeding('backup'), priority: 10);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('backup', $result->providerKey);
        $this->assertSame(1, $broken->calls, 'an authentication failure is not worth repeating');
        $this->assertSame(1, $backup->calls);
        $this->assertSame(1, $result->fallbacks);
    }

    // --------------------------------------------------------- fallback --

    public function test_the_request_falls_through_the_chain_until_one_provider_answers(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.enabled' => false,
        ]);

        $first = $this->registerProvider('first', RecordingProvider::failing('first', ProviderTimeoutException::forProvider('first')), priority: 90);
        $second = $this->registerProvider('second', RecordingProvider::failing('second', ProviderTimeoutException::forProvider('second')), priority: 50);
        $third = $this->registerProvider('third', RecordingProvider::succeeding('third'), priority: 10);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('third', $result->providerKey);
        $this->assertSame(2, $result->fallbacks);
        $this->assertSame(1, $first->calls);
        $this->assertSame(1, $second->calls);
        $this->assertSame(1, $third->calls);
    }

    public function test_the_fallback_chain_is_bounded_by_configuration(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.enabled' => false,
            'ai.routing.fallback.max_providers' => 2,
        ]);

        $first = $this->registerProvider('first', RecordingProvider::failing('first', ProviderTimeoutException::forProvider('first')), priority: 90);
        $second = $this->registerProvider('second', RecordingProvider::failing('second', ProviderTimeoutException::forProvider('second')), priority: 50);
        $third = $this->registerProvider('third', RecordingProvider::succeeding('third'), priority: 10);

        try {
            $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));
            $this->fail('The bounded chain should have been exhausted.');
        } catch (AllProvidersFailedException $exception) {
            $this->assertSame('ai_all_providers_failed', $exception->errorCode());
        }

        $this->assertSame(1, $first->calls);
        $this->assertSame(1, $second->calls);
        $this->assertSame(0, $third->calls, 'the third provider is beyond the configured bound');
    }

    public function test_disabling_fallback_confines_a_request_to_one_provider(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.enabled' => false,
            'ai.routing.fallback.enabled' => false,
        ]);

        $first = $this->registerProvider('first', RecordingProvider::failing('first', ProviderTimeoutException::forProvider('first')), priority: 90);
        $second = $this->registerProvider('second', RecordingProvider::succeeding('second'), priority: 10);

        $this->expectException(AllProvidersFailedException::class);

        try {
            $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));
        } finally {
            $this->assertSame(1, $first->calls);
            $this->assertSame(0, $second->calls);
        }
    }

    public function test_every_provider_failing_reports_the_whole_attempt_trail(): void
    {
        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        $this->registerProvider('first', RecordingProvider::failing('first', ProviderTimeoutException::forProvider('first')), priority: 90);
        $this->registerProvider('second', RecordingProvider::failing('second', ProviderTimeoutException::forProvider('second')), priority: 10);

        try {
            $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));
            $this->fail('Every provider failed; the dispatch should not have returned.');
        } catch (AllProvidersFailedException $exception) {
            $context = $exception->context();

            $this->assertSame(Capability::Text->value, $context['capability']);
            $this->assertCount(2, $context['attempts']);
            $this->assertSame(['first', 'second'], array_column($context['attempts'], 'provider'));
            $this->assertInstanceOf(ProviderTimeoutException::class, $exception->getPrevious());
        }
    }

    public function test_a_failure_outside_the_fallback_policy_surfaces_immediately(): void
    {
        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        // A RuntimeException is not an AIException, so it is a bug rather than
        // an outage: the chain must not swallow it.
        $first = $this->registerProvider('first', RecordingProvider::failing('first', new RuntimeException('a bug')), priority: 90);
        $second = $this->registerProvider('second', RecordingProvider::succeeding('second'), priority: 10);

        $this->expectException(RuntimeException::class);

        try {
            $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));
        } finally {
            $this->assertSame(1, $first->calls);
            $this->assertSame(0, $second->calls);
        }
    }

    public function test_an_unsupported_capability_falls_through_to_a_provider_that_serves_it(): void
    {
        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        // Declares text but refuses it at call time — a stale declaration.
        $stale = $this->registerProvider(
            'stale',
            RecordingProvider::failing('stale', UnsupportedCapabilityException::make('stale', Capability::Text)),
            priority: 90,
        );
        $this->registerProvider('honest', RecordingProvider::succeeding('honest'), priority: 10);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('honest', $result->providerKey);
        $this->assertSame(1, $stale->calls);
    }

    // -------------------------------------------------- circuit breaker --

    public function test_a_failing_provider_trips_its_circuit_and_is_skipped_afterwards(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.enabled' => false,
            'ai.circuit_breaker.failure_threshold' => 1,
            'ai.routing.health.enabled' => false,
        ]);

        $bad = $this->registerProvider('bad', RecordingProvider::failing('bad', ProviderTimeoutException::forProvider('bad')), priority: 90);
        $good = $this->registerProvider('good', RecordingProvider::succeeding('good'), priority: 10);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'first'));

        $this->assertSame(CircuitState::Open, $this->breaker()->state('bad'));

        $second = $this->dispatcher()->dispatch(new TextRequest(prompt: 'second'));

        $this->assertSame('good', $second->providerKey);
        $this->assertSame(1, $bad->calls, 'the open circuit spares the provider a second call');
        $this->assertSame(2, $good->calls);
    }

    public function test_a_successful_call_records_healthy_observed_state(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame(HealthStatus::Healthy, $this->healthTracker()->status('writer'));
        $this->assertSame('observed', $this->healthTracker()->snapshot('writer')->details['source']);
    }

    public function test_a_failed_call_degrades_observed_health(): void
    {
        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        $this->registerProvider('bad', RecordingProvider::failing('bad', ProviderTimeoutException::forProvider('bad')), priority: 90);
        $this->registerProvider('good', RecordingProvider::succeeding('good'), priority: 10);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame(HealthStatus::Degraded, $this->healthTracker()->status('bad'));
        $this->assertSame(HealthStatus::Healthy, $this->healthTracker()->status('good'));
    }

    // ---------------------------------------------------------- timeout --

    public function test_an_exhausted_deadline_stops_the_chain_gracefully(): void
    {
        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        $this->registerProvider('first', RecordingProvider::failing('first', ProviderTimeoutException::forProvider('first')), priority: 90);
        $second = $this->registerProvider('second', RecordingProvider::succeeding('second'), priority: 10);

        try {
            // A one-millisecond budget is already spent by the time the chain
            // starts, so it is abandoned rather than continued at any cost.
            $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'), DispatchOptions::make()->withTimeout(1));
            $this->fail('The deadline should have ended the dispatch.');
        } catch (AllProvidersFailedException $exception) {
            $skipped = array_column($exception->context()['attempts'], 'skipped');

            $this->assertContains('deadline_exhausted', $skipped);
        }

        $this->assertSame(0, $second->calls, 'the deadline stopped the chain before the fallback');
    }

    // ---------------------------------------------------------- metrics --

    public function test_metrics_record_usage_outcome_latency_retries_and_fallbacks(): void
    {
        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 1,
        ]);

        $this->registerProvider(
            'flaky',
            RecordingProvider::failing('flaky', ProviderTimeoutException::forProvider('flaky')),
            priority: 90,
        );
        $this->registerProvider('steady', RecordingProvider::succeeding('steady'), priority: 10);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $flaky = $this->metrics()->forProvider('flaky');
        $steady = $this->metrics()->forProvider('steady');

        $this->assertSame(3, $flaky->requests, 'three attempts against the first provider');
        $this->assertSame(3, $flaky->failures);
        $this->assertSame(2, $flaky->retries);
        $this->assertSame(1, $flaky->fallbacks);
        $this->assertSame(0.0, $flaky->successRate());
        $this->assertSame(1.0, $flaky->failureRate());

        $this->assertSame(1, $steady->requests);
        $this->assertSame(1, $steady->successes);
        $this->assertSame(1.0, $steady->successRate());

        $snapshot = $this->metrics()->snapshot();

        $this->assertSame(4, $snapshot->totals()->requests);
        $this->assertArrayHasKey(Capability::Text->value, $snapshot->capabilities);
        $this->assertSame(4, $snapshot->capabilities[Capability::Text->value]->requests);
    }

    public function test_metrics_accumulate_the_estimated_cost_of_each_call(): void
    {
        $this->configurePlatform(['ai.cost.enabled' => true, 'ai.routing.strategy' => 'priority']);

        $this->registerProvider('writer', RecordingProvider::succeeding('writer', cost: 0.25));

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));
        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello again'));

        $this->assertEqualsWithDelta(0.5, $this->metrics()->forProvider('writer')->estimatedCost, 0.000001);
    }

    public function test_average_response_time_is_derived_from_recorded_calls(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $metrics = $this->metrics()->forProvider('writer');

        $this->assertSame(1, $metrics->requests);
        $this->assertGreaterThanOrEqual(0.0, $metrics->averageResponseMs());
    }

    public function test_flushing_metrics_clears_every_counter(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));
        $this->metrics()->flush();

        $this->assertSame(0, $this->metrics()->forProvider('writer')->requests);
        $this->assertSame([], $this->metrics()->snapshot()->providers);
    }

    public function test_metrics_can_be_switched_off_without_touching_the_dispatch_path(): void
    {
        $this->configurePlatform(['ai.metrics.enabled' => false, 'ai.routing.strategy' => 'priority']);

        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        $this->assertSame('writer', $result->providerKey);
        $this->assertSame(0, $this->metrics()->forProvider('writer')->requests);
    }

    // ------------------------------------------------------------ cache --

    public function test_a_provider_is_built_once_and_reused(): void
    {
        $built = 0;

        $this->providerRegistry()->register(
            'counted',
            function () use (&$built): RecordingProvider {
                $built++;

                return RecordingProvider::succeeding('counted');
            },
            new ProviderCapabilityDTO('counted', 'Counted', '1.0.0-test', text: true),
        );

        $cache = $this->app->make(ProviderInstanceCache::class);
        $cache->flush();

        $first = $cache->get('counted');
        $second = $cache->get('counted');

        $this->assertSame(1, $built, 'the second resolution comes from the cache');
        $this->assertSame($first, $second);
        $this->assertTrue($cache->has('counted'));
    }

    public function test_disabling_a_provider_invalidates_its_cached_instance(): void
    {
        $this->registerProvider('writer', RecordingProvider::succeeding('writer'));

        $cache = $this->app->make(ProviderInstanceCache::class);
        $cache->get('writer');

        $this->providerRegistry()->disable('writer');

        $this->expectException(ProviderDisabledException::class);

        $cache->get('writer');
    }

    public function test_health_probes_are_cached_for_their_configured_ttl(): void
    {
        $probes = 0;

        $this->providerRegistry()->register(
            'probed',
            function () use (&$probes): RecordingProvider {
                $probes++;

                return RecordingProvider::succeeding('probed');
            },
            new ProviderCapabilityDTO('probed', 'Probed', '1.0.0-test', text: true),
        );

        $health = $this->app->make(HealthManagerInterface::class);

        $health->check('probed');
        $health->check('probed');
        $health->aggregate();

        // The provider is built once for the probe, then the result is reused.
        $this->assertSame(1, $probes);
    }

    // ---------------------------------------------------- no stray HTTP --

    public function test_a_full_dispatch_reaches_no_network(): void
    {
        $this->registerProvider('flaky', RecordingProvider::recoveringAfter('flaky', 1, ProviderTimeoutException::forProvider('flaky')), priority: 90);
        $this->registerProvider('backup', RecordingProvider::succeeding('backup'), priority: 10);

        $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'));

        Http::assertNothingSent();
    }

    public function test_the_result_serialises_its_whole_decision_trail(): void
    {
        $this->configurePlatform(['ai.routing.strategy' => 'priority', 'ai.retry.enabled' => false]);

        $this->registerProvider('first', RecordingProvider::failing('first', ProviderTimeoutException::forProvider('first')), priority: 90);
        $this->registerProvider('second', RecordingProvider::succeeding('second'), priority: 10);

        $result = $this->dispatcher()->dispatch(new TextRequest(prompt: 'hello'))->toArray();

        $this->assertSame('second', $result['provider']);
        $this->assertSame(1, $result['fallbacks']);
        $this->assertSame(['first', 'second'], $result['plan']);
        $this->assertSame(['first', 'second'], array_column($result['attempts'], 'provider'));
        $this->assertSame('ai_provider_timeout', $result['attempts'][0]['error_code']);
    }

    public function test_the_platform_configuration_is_parsed_once_per_process(): void
    {
        $first = $this->app->make(PlatformConfig::class);
        $second = $this->app->make(PlatformConfig::class);

        $this->assertSame($first, $second);
    }
}
