<?php

declare(strict_types=1);

namespace App\AI\Execution;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Contracts\ProviderResponseInterface;
use App\AI\Exceptions\UnsupportedModalityException;
use App\AI\Support\Modality;

/**
 * The modalities the platform knows how to invoke.
 *
 * Registered once at boot from {@see AIProviderInterface}'s
 * generative surface. It exists so capability routing stays open for extension:
 * a future modality — video that a provider genuinely serves, embeddings once
 * the contract gains them — is adopted by registering an invoker here, and the
 * router, the dispatcher and the strategies all keep working unchanged.
 */
final class ModalityInvokerRegistry
{
    /**
     * @var array<string, ModalityInvoker>
     */
    private array $invokers = [];

    public function register(ModalityInvoker $invoker): void
    {
        $this->invokers[$invoker->modality->value] = $invoker;
    }

    public function has(Modality $modality): bool
    {
        return isset($this->invokers[$modality->value]);
    }

    /**
     * @throws UnsupportedModalityException When no invoker is registered.
     */
    public function for(Modality $modality): ModalityInvoker
    {
        return $this->invokers[$modality->value] ?? throw UnsupportedModalityException::make($modality);
    }

    /**
     * Call the provider method that serves the request's modality.
     */
    public function invoke(AIProviderInterface $provider, ProviderRequestInterface $request): ProviderResponseInterface
    {
        return $this->for($request->modality())->invoke($provider, $request);
    }

    /**
     * @return list<Modality>
     */
    public function modalities(): array
    {
        return array_map(
            static fn (ModalityInvoker $invoker): Modality => $invoker->modality,
            array_values($this->invokers),
        );
    }
}
