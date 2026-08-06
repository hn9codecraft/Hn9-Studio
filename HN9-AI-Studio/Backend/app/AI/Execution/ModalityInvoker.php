<?php

declare(strict_types=1);

namespace App\AI\Execution;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Exceptions\UnsupportedModalityException;
use App\AI\Support\Modality;
use Closure;

/**
 * Binds a modality to the provider method that serves it.
 *
 * This is what keeps the dispatcher free of a `match` over modalities: it holds
 * a request, asks the registry for the invoker, and calls it. Supporting a new
 * modality means registering another invoker, not editing the execution path.
 *
 * The invoker is generic in its request type, so the closure is written against
 * the concrete request the provider method expects and the binding between the
 * two is checked statically at the registration site.
 *
 * @template TRequest of ProviderRequestInterface
 */
final readonly class ModalityInvoker
{
    /**
     * @param  class-string<TRequest>  $requestClass  The typed request this invoker accepts.
     * @param  Closure(AIProviderInterface, TRequest): ProviderResponseInterface  $call
     */
    public function __construct(
        public Modality $modality,
        public string $requestClass,
        private Closure $call,
    ) {}

    /**
     * @throws UnsupportedModalityException When the request is not the type this invoker serves.
     */
    public function invoke(AIProviderInterface $provider, ProviderRequestInterface $request): ProviderResponseInterface
    {
        if (! $request instanceof $this->requestClass) {
            throw UnsupportedModalityException::mismatchedRequest(
                $this->modality,
                $this->requestClass,
                $request::class,
            );
        }

        return ($this->call)($provider, $request);
    }
}
