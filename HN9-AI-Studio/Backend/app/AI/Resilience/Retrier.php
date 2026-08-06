<?php

declare(strict_types=1);

namespace App\AI\Resilience;

use App\AI\Contracts\RetryPolicyInterface;
use Closure;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs an operation under a {@see RetryPolicyInterface}.
 *
 * The retrier owns the loop and the waiting; the policy owns the decisions. It
 * knows nothing about providers or requests, so the same instance serves any
 * call the platform makes.
 *
 * Waiting goes through Laravel's `Sleep`, which tests fake — retry behaviour is
 * therefore verified without a suite that actually sleeps.
 */
final readonly class Retrier
{
    /**
     * Execute an operation, repeating it while the policy allows.
     *
     * @template TReturn
     *
     * @param  Closure(int): TReturn  $operation  Receives the 1-based attempt number.
     * @param  Closure(int, Throwable, int): void|null  $onRetry  Attempt, failure, delay.
     * @return TReturn
     *
     * @throws Throwable The final failure, once retrying stops.
     */
    public function run(
        RetryPolicyInterface $policy,
        Closure $operation,
        ?Deadline $deadline = null,
        ?Closure $onRetry = null,
    ): mixed {
        $attempt = 1;

        while (true) {
            try {
                return $operation($attempt);
            } catch (Throwable $failure) {
                if (! $policy->shouldRetry($failure, $attempt)) {
                    throw $failure;
                }

                $delay = $policy->delayFor($attempt);

                // Waiting is only worth it if the answer can still arrive in time.
                if ($deadline !== null && ! $deadline->allows($delay)) {
                    throw $failure;
                }

                $onRetry?->__invoke($attempt, $failure, $delay);

                if ($delay > 0) {
                    Sleep::for($delay)->milliseconds();
                }

                $attempt++;
            }
        }
    }
}
