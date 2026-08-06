<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Execution\ModalityInvokerRegistry;
use App\AI\Support\Modality;

/**
 * Thrown when the platform is asked to dispatch a modality no invoker has been
 * registered for.
 *
 * Distinct from {@see UnsupportedCapabilityException}, which means a provider
 * cannot serve a modality the platform does understand. This one means the
 * platform itself has no way to invoke it — the extension point for future
 * modalities is {@see ModalityInvokerRegistry}.
 */
final class UnsupportedModalityException extends AIException
{
    public static function make(Modality $modality): self
    {
        return new self(
            message: "No invoker is registered for the [{$modality->value}] modality.",
            errorCode: 'ai_unsupported_modality',
            statusCode: 422,
            context: ['modality' => $modality->value],
        );
    }

    public static function mismatchedRequest(Modality $modality, string $expected, string $actual): self
    {
        return new self(
            message: "The [{$modality->value}] invoker expects [{$expected}], received [{$actual}].",
            errorCode: 'ai_modality_request_mismatch',
            statusCode: 422,
            context: ['modality' => $modality->value, 'expected' => $expected, 'actual' => $actual],
        );
    }
}
