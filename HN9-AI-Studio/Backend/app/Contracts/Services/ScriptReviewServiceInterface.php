<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\Script;
use App\Models\ScriptReviewEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Approval, rework and review-history operations for studio scripts.
 */
interface ScriptReviewServiceInterface
{
    public function submit(Script $script, User $actor, ?string $comment = null): Script;

    public function approve(Script $script, User $actor, ?string $comment = null): Script;

    public function requestRework(Script $script, User $actor, string $comment): Script;

    public function recordReworked(Script $script, User $actor): ScriptReviewEvent;

    /**
     * @return Collection<int, ScriptReviewEvent>
     */
    public function history(Script $script): Collection;
}
