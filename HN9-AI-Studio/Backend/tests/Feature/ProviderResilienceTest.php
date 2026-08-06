<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Config\PlatformConfig;
use App\AI\Contracts\RetryPolicyInterface;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Resilience\Deadline;
use App\AI\Resilience\Retrier;
use App\AI\Support\Capability;
use App\AI\Support\CircuitState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

/**
 * The retry policy, the retrier and the circuit breaker in isolation.
 *
 * Waiting is faked throughout, so backoff is asserted exactly rather than
 * approximated by a suite that actually sleeps.
 */
class ProviderResilienceTest extends TestCase
{
    use InteractsWithProviderPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        // Jitter is randomness; the tests assert the deterministic backoff.
        $this->configurePlatform(['ai.retry.jitter' => false]);
    }

    private function policy(): RetryPolicyInterface
    {
        return $this->app->make(RetryPolicyInterface::class);
    }

    private function retrier(): Retrier
    {
        return $this->app->make(Retrier::class);
    }

    // ------------------------------------------------------------ retry --

    public function test_a_transient_failure_is_retried_up_to_the_attempt_budget(): void
    {
        $attempts = 0;

        try {
            $this->retrier()->run($this->policy(), function () use (&$attempts): never {
                $attempts++;

                throw ProviderTimeoutException::forProvider('any');
            });
            $this->fail('The retrier should surface the final failure.');
        } catch (ProviderTimeoutException) {
            // Expected: the budget is spent and the last failure is rethrown.
        }

        $this->assertSame(3, $attempts, 'the configured budget is three attempts');
    }

    public function test_a_retry_stops_as_soon_as_the_operation_succeeds(): void
    {
        $attempts = 0;

        $result = $this->retrier()->run($this->policy(), function () use (&$attempts): string {
            $attempts++;

            if ($attempts < 2) {
                throw ProviderNetworkException::forProvider('any', new RuntimeException('socket'));
            }

            return 'recovered';
        });

        $this->assertSame('recovered', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_a_non_retryable_failure_is_never_repeated(): void
    {
        $attempts = 0;

        $this->expectException(ProviderAuthenticationException::class);

        try {
            $this->retrier()->run($this->policy(), function () use (&$attempts): never {
                $attempts++;

                throw ProviderAuthenticationException::forProvider('any');
            });
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_an_unclassified_failure_is_never_repeated(): void
    {
        $attempts = 0;

        $this->expectException(RuntimeException::class);

        try {
            $this->retrier()->run($this->policy(), function () use (&$attempts): never {
                $attempts++;

                throw new RuntimeException('a bug, not a blip');
            });
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_the_delay_grows_exponentially_and_is_capped(): void
    {
        $this->configurePlatform([
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 100,
            'ai.retry.multiplier' => 3.0,
            'ai.retry.max_delay_ms' => 500,
        ]);

        $policy = $this->policy();

        $this->assertSame(100, $policy->delayFor(1));
        $this->assertSame(300, $policy->delayFor(2));
        $this->assertSame(500, $policy->delayFor(3), 'capped at max_delay_ms');
    }

    public function test_the_retrier_waits_the_backoff_between_attempts(): void
    {
        $this->configurePlatform([
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 50,
            'ai.retry.multiplier' => 2.0,
            'ai.retry.max_attempts' => 3,
        ]);

        try {
            $this->retrier()->run($this->policy(), static fn (): never => throw ProviderTimeoutException::forProvider('any'));
        } catch (ProviderTimeoutException) {
            // The failure is not what this test is about.
        }

        Sleep::assertSequence([
            Sleep::for(50)->milliseconds(),
            Sleep::for(100)->milliseconds(),
        ]);
    }

    public function test_jitter_keeps_the_delay_within_its_configured_spread(): void
    {
        $this->configurePlatform([
            'ai.retry.jitter' => true,
            'ai.retry.jitter_ratio' => 0.5,
            'ai.retry.delay_ms' => 100,
            'ai.retry.multiplier' => 1.0,
        ]);

        $policy = $this->policy();

        for ($i = 0; $i < 20; $i++) {
            $delay = $policy->delayFor(1);

            $this->assertGreaterThanOrEqual(100, $delay);
            $this->assertLessThanOrEqual(150, $delay);
        }
    }

    public function test_retrying_is_abandoned_when_the_deadline_cannot_absorb_the_wait(): void
    {
        $this->configurePlatform(['ai.retry.jitter' => false, 'ai.retry.delay_ms' => 5_000]);

        $attempts = 0;

        $this->expectException(ProviderTimeoutException::class);

        try {
            $this->retrier()->run(
                $this->policy(),
                function () use (&$attempts): never {
                    $attempts++;

                    throw ProviderTimeoutException::forProvider('any');
                },
                Deadline::afterMilliseconds(50),
            );
        } finally {
            $this->assertSame(1, $attempts, 'a 5s wait cannot fit inside a 50ms budget');
        }
    }

    public function test_retrying_can_be_disabled_outright(): void
    {
        $this->configurePlatform(['ai.retry.enabled' => false]);

        $this->assertSame(1, $this->policy()->maxAttempts());
    }

    public function test_a_caller_may_narrow_the_attempt_budget(): void
    {
        $this->assertSame(2, $this->policy()->withMaxAttempts(2)->maxAttempts());
    }

    // -------------------------------------------------- circuit breaker --

    public function test_a_circuit_opens_once_the_failure_threshold_is_reached(): void
    {
        $this->configurePlatform(['ai.circuit_breaker.failure_threshold' => 3]);

        $breaker = $this->breaker();

        $breaker->recordFailure('vendor');
        $breaker->recordFailure('vendor');

        $this->assertSame(CircuitState::Closed, $breaker->state('vendor'));
        $this->assertTrue($breaker->allows('vendor'));

        $breaker->recordFailure('vendor');

        $this->assertSame(CircuitState::Open, $breaker->state('vendor'));
        $this->assertFalse($breaker->allows('vendor'));
        $this->assertSame(3, $breaker->failures('vendor'));
    }

    public function test_a_success_clears_an_accumulating_failure_run(): void
    {
        $this->configurePlatform(['ai.circuit_breaker.failure_threshold' => 3]);

        $breaker = $this->breaker();

        $breaker->recordFailure('vendor');
        $breaker->recordFailure('vendor');
        $breaker->recordSuccess('vendor');

        $this->assertSame(0, $breaker->failures('vendor'));

        $breaker->recordFailure('vendor');

        $this->assertSame(CircuitState::Closed, $breaker->state('vendor'));
    }

    public function test_an_open_circuit_becomes_half_open_once_the_recovery_timeout_elapses(): void
    {
        $this->configurePlatform([
            'ai.circuit_breaker.failure_threshold' => 1,
            'ai.circuit_breaker.recovery_timeout' => 60,
        ]);

        Carbon::setTestNow('2026-08-02 12:00:00');

        $breaker = $this->breaker();
        $breaker->recordFailure('vendor');

        $this->assertFalse($breaker->allows('vendor'));

        Carbon::setTestNow('2026-08-02 12:00:30');
        $this->assertFalse($breaker->allows('vendor'), 'still inside the recovery window');

        Carbon::setTestNow('2026-08-02 12:01:00');
        $this->assertSame(CircuitState::HalfOpen, $breaker->state('vendor'));
        $this->assertTrue($breaker->allows('vendor'), 'a trial call is admitted');

        Carbon::setTestNow();
    }

    public function test_enough_half_open_successes_close_the_circuit(): void
    {
        $this->configurePlatform([
            'ai.circuit_breaker.failure_threshold' => 1,
            'ai.circuit_breaker.success_threshold' => 2,
            'ai.circuit_breaker.recovery_timeout' => 60,
        ]);

        Carbon::setTestNow('2026-08-02 12:00:00');

        $breaker = $this->breaker();
        $breaker->recordFailure('vendor');

        Carbon::setTestNow('2026-08-02 12:01:00');
        $breaker->allows('vendor');

        $breaker->recordSuccess('vendor');
        $this->assertSame(CircuitState::HalfOpen, $breaker->state('vendor'), 'one success is not enough');

        $breaker->recordSuccess('vendor');
        $this->assertSame(CircuitState::Closed, $breaker->state('vendor'));
        $this->assertSame(0, $breaker->failures('vendor'));

        Carbon::setTestNow();
    }

    public function test_a_failed_trial_reopens_the_circuit_immediately(): void
    {
        $this->configurePlatform([
            'ai.circuit_breaker.failure_threshold' => 5,
            'ai.circuit_breaker.recovery_timeout' => 60,
        ]);

        Carbon::setTestNow('2026-08-02 12:00:00');

        $breaker = $this->breaker();

        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure('vendor');
        }

        Carbon::setTestNow('2026-08-02 12:01:00');
        $breaker->allows('vendor');
        $this->assertSame(CircuitState::HalfOpen, $breaker->state('vendor'));

        // One failure is enough: the provider has not recovered.
        $breaker->recordFailure('vendor');

        $this->assertSame(CircuitState::Open, $breaker->state('vendor'));
        $this->assertFalse($breaker->allows('vendor'));

        Carbon::setTestNow();
    }

    public function test_a_disabled_breaker_never_withholds_traffic(): void
    {
        $this->configurePlatform([
            'ai.circuit_breaker.enabled' => false,
            'ai.circuit_breaker.failure_threshold' => 1,
        ]);

        $breaker = $this->breaker();
        $breaker->recordFailure('vendor');

        $this->assertTrue($breaker->allows('vendor'));
        $this->assertSame(CircuitState::Closed, $breaker->state('vendor'));
    }

    public function test_only_provider_side_failures_count_against_a_circuit(): void
    {
        $config = $this->app->make(PlatformConfig::class)->circuitBreaker;

        $this->assertTrue($config->trips(ProviderTimeoutException::forProvider('v')));
        $this->assertTrue($config->trips(ProviderApiException::forProvider('v', 'upstream exploded')));
        $this->assertFalse($config->trips(UnsupportedCapabilityException::make('v', Capability::Video)));
        $this->assertFalse($config->trips(new RuntimeException('a bug')));
    }

    public function test_resetting_a_circuit_returns_it_to_closed(): void
    {
        $this->configurePlatform(['ai.circuit_breaker.failure_threshold' => 1]);

        $breaker = $this->breaker();
        $breaker->recordFailure('vendor');
        $breaker->reset('vendor');

        $this->assertSame(CircuitState::Closed, $breaker->state('vendor'));
        $this->assertTrue($breaker->allows('vendor'));
    }

    // --------------------------------------------------------- deadline --

    public function test_an_unbounded_deadline_never_expires(): void
    {
        $deadline = Deadline::none();

        $this->assertFalse($deadline->isBounded());
        $this->assertNull($deadline->remainingMs());
        $this->assertFalse($deadline->exhausted());
        $this->assertTrue($deadline->allows(1_000_000));
    }

    public function test_a_bounded_deadline_reports_what_still_fits(): void
    {
        $deadline = Deadline::afterMilliseconds(500);

        $this->assertTrue($deadline->isBounded());
        $this->assertTrue($deadline->allows(100));
        $this->assertFalse($deadline->allows(5_000));
        $this->assertFalse($deadline->exhausted());
    }
}
