<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Generation\GenerationRequestData;
use App\Models\GeneratedContent;
use App\Models\User;

/**
 * Re-runs the generation pipeline for content that already exists.
 *
 * This owns no generation logic of its own. It derives a fresh
 * {@see GenerationRequestData} from the stored content,
 * recovers the variables the original prompt was rendered with, and hands the
 * whole run to {@see ExecutionOrchestratorInterface}.
 *
 * It is a separate service rather than a method on {@see ContentServiceInterface}
 * because the orchestrator already depends on that contract; injecting the
 * orchestrator back into it would be a circular container dependency.
 */
interface ContentRegenerationServiceInterface
{
    /**
     * Regenerate content through the existing pipeline.
     *
     * @param  array<string, mixed>  $overrides  Merged over the recovered request:
     *                                           `variables`, `payload`, `model`,
     *                                           `template_key`, `topic`, `goal`.
     * @return array<string, mixed> The orchestrator's pipeline result.
     */
    public function regenerate(GeneratedContent $content, array $overrides = [], ?User $causer = null): array;
}
