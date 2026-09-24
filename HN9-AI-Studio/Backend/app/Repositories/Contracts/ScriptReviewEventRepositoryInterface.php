<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Script;
use App\Models\ScriptReviewEvent;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<ScriptReviewEvent>
 */
interface ScriptReviewEventRepositoryInterface extends RepositoryInterface
{
    /**
     * Chronological review history for one script.
     *
     * @param  list<string>  $with
     * @return Collection<int, ScriptReviewEvent>
     */
    public function listForScript(Script $script, array $with = []): Collection;
}
