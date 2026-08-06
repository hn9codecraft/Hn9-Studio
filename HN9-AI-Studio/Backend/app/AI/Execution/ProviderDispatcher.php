<?php

declare(strict_types=1);

namespace App\AI\Execution;

use App\AI\Cache\ProviderInstanceCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\CircuitBreakerInterface;
use App\AI\Contracts\HealthTrackerInterface;
use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Contracts\ProviderRouterInterface;
use App\AI\Contracts\RetryPolicyInterface;
use App\AI\Exceptions\AIException;
use App\AI\Exceptions\AllProvidersFailedException;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Resilience\Deadline;
use App\AI\Resilience\Retrier;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\VideoResponse;
use App\AI\Responses\VoiceResponse;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;
use App\AI\Support\Modality;
use Throwable;
use TypeError;

/**
 * Executes a routed request, applying every resilience concern around it.
 *
 * One dispatch is: plan the providers, then walk the plan. Each provider gets
 * the retry policy's attempt budget; a provider whose circuit is open is
 * skipped without a call; a failure the fallback policy covers hands the
 * request to the next candidate; and the whole walk runs inside a single
 * deadline so retries and fallbacks cannot compound without bound.
 *
 * Every outcome is recorded — circuit, observed health, metrics — which is what
 * lets the *next* request route better than this one did.
 *
 * The dispatcher names no provider and switches on none. It resolves keys the
 * router produced, and invokes modalities through the invoker registry, so both
 * the provider set and the modality set stay open for extension.
 */
final readonly class ProviderDispatcher implements ProviderDispatcherInterface
{
    public function __construct(
        private ProviderRouterInterface $router,
        private ProviderInstanceCache $providers,
        private ModalityInvokerRegistry $invokers,
        private RetryPolicyInterface $policy,
        private Retrier $retrier,
        private CircuitBreakerInterface $breaker,
        private HealthTrackerInterface $healthTracker,
        private MetricsCollectorInterface $metrics,
        private PlatformConfig $config,
    ) {}

    public function dispatch(ProviderRequestInterface $request, ?DispatchOptions $options = null): DispatchResult
    {
        $options ??= DispatchOptions::make();
        $context = RoutingContext::for($request, $options, $this->config);

        $plan = $this->router->route($context)->limitTo(
            $this->config->routing->fallback->providerBudget($options->maxProviders),
        );

        $deadline = Deadline::afterMilliseconds($this->config->timeouts->budgetMs($options->timeoutMs));
        $policy = $options->maxAttempts === null
            ? $this->policy
            : $this->policy->withMaxAttempts($options->maxAttempts);

        /** @var list<AttemptRecord> $attempts */
        $attempts = [];
        $retries = 0;
        $fallbacks = 0;
        $previous = null;
        $failure = null;
        $startedAt = hrtime(true);

        foreach ($plan->candidates as $candidate) {
            if ($deadline->exhausted()) {
                $attempts[] = AttemptRecord::skipped($candidate->key, 'deadline_exhausted');

                break;
            }

            // Re-checked here as well as in routing: a circuit can open between
            // planning and this provider's turn in the chain.
            if (! $this->breaker->allows($candidate->key)) {
                $attempts[] = AttemptRecord::skipped($candidate->key, 'circuit_open');

                continue;
            }

            if ($previous !== null) {
                $fallbacks++;
                $this->metrics->recordFallback($previous, $candidate->key, $context->capability);
            }

            $previous = $candidate->key;

            try {
                $response = $this->attempt($candidate, $request, $context, $policy, $deadline, $attempts, $retries);

                return new DispatchResult(
                    providerKey: $candidate->key,
                    response: $response,
                    modality: $request->modality(),
                    durationMs: $this->elapsedMs($startedAt),
                    retries: $retries,
                    fallbacks: $fallbacks,
                    estimatedCost: $candidate->estimatedCost ?? 0.0,
                    attempts: $attempts,
                    plan: $plan,
                );
            } catch (Throwable $thrown) {
                $failure = $thrown;

                // A failure outside the configured fallback set is the caller's
                // problem, not the provider's: surface it rather than burning
                // the rest of the chain on a request that cannot succeed.
                if (! $this->config->routing->fallback->coversFailure($thrown)) {
                    throw $thrown;
                }
            }
        }

        throw AllProvidersFailedException::make(
            $context->capability,
            array_map(static fn (AttemptRecord $attempt): array => $attempt->toArray(), $attempts),
            $failure,
        );
    }

    public function text(TextRequest $request, ?DispatchOptions $options = null): TextResponse
    {
        $response = $this->dispatch($request, $options)->response;

        return $response instanceof TextResponse
            ? $response
            : throw $this->unexpected(Modality::Text, TextResponse::class, $response);
    }

    public function image(ImageRequest $request, ?DispatchOptions $options = null): ImageResponse
    {
        $response = $this->dispatch($request, $options)->response;

        return $response instanceof ImageResponse
            ? $response
            : throw $this->unexpected(Modality::Image, ImageResponse::class, $response);
    }

    public function voice(VoiceRequest $request, ?DispatchOptions $options = null): VoiceResponse
    {
        $response = $this->dispatch($request, $options)->response;

        return $response instanceof VoiceResponse
            ? $response
            : throw $this->unexpected(Modality::Voice, VoiceResponse::class, $response);
    }

    public function video(VideoRequest $request, ?DispatchOptions $options = null): VideoResponse
    {
        $response = $this->dispatch($request, $options)->response;

        return $response instanceof VideoResponse
            ? $response
            : throw $this->unexpected(Modality::Video, VideoResponse::class, $response);
    }

    /**
     * Call one provider under the retry policy, recording every outcome.
     *
     * @param  list<AttemptRecord>  $attempts
     */
    private function attempt(
        ProviderCandidate $candidate,
        ProviderRequestInterface $request,
        RoutingContext $context,
        RetryPolicyInterface $policy,
        Deadline $deadline,
        array &$attempts,
        int &$retries,
    ): ProviderResponseInterface {
        try {
            $provider = $this->providers->get($candidate->key);
        } catch (Throwable $failure) {
            // Resolution failures are the registry's or configuration's, not the
            // vendor's: they are logged as an attempt but never trip a circuit.
            $attempts[] = AttemptRecord::failure(
                $candidate->key, 0, 0, $failure->getMessage(), $this->errorCode($failure),
            );

            throw $failure;
        }

        $invoker = $this->invokers->for($request->modality());

        return $this->retrier->run(
            policy: $policy,
            operation: function (int $attempt) use ($provider, $invoker, $request, $candidate, $context, &$attempts): ProviderResponseInterface {
                $startedAt = hrtime(true);

                try {
                    $response = $invoker->invoke($provider, $request);
                    $elapsed = $this->elapsedMs($startedAt);

                    $attempts[] = AttemptRecord::success($candidate->key, $attempt, $elapsed);
                    $this->breaker->recordSuccess($candidate->key);
                    $this->healthTracker->recordSuccess($candidate->key, $elapsed);
                    $this->metrics->recordSuccess(
                        $candidate->key, $context->capability, $elapsed, $candidate->estimatedCost ?? 0.0,
                    );

                    return $response;
                } catch (Throwable $failure) {
                    $elapsed = $this->elapsedMs($startedAt);
                    $code = $this->errorCode($failure);

                    $attempts[] = AttemptRecord::failure(
                        $candidate->key, $attempt, $elapsed, $failure->getMessage(), $code,
                    );

                    // Only failures that say something about the provider's own
                    // condition count against its circuit and its health.
                    if ($this->config->circuitBreaker->trips($failure)) {
                        $this->breaker->recordFailure($candidate->key);
                        $this->healthTracker->recordFailure($candidate->key, $failure);
                    }

                    $this->metrics->recordFailure($candidate->key, $context->capability, $elapsed, $code);

                    throw $failure;
                }
            },
            deadline: $deadline,
            onRetry: function () use (&$retries, $candidate, $context): void {
                $retries++;
                $this->metrics->recordRetry($candidate->key, $context->capability);
            },
        );
    }

    private function errorCode(Throwable $failure): string
    {
        return $failure instanceof AIException ? $failure->errorCode() : 'unhandled_exception';
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * A provider answered a modality with the wrong response type — a broken
     * adapter, not a routing outcome, so it is raised as a type error.
     */
    private function unexpected(Modality $modality, string $expected, ProviderResponseInterface $actual): TypeError
    {
        return new TypeError(sprintf(
            'The [%s] dispatch expected a %s, received a %s.',
            $modality->value,
            $expected,
            $actual::class,
        ));
    }
}
