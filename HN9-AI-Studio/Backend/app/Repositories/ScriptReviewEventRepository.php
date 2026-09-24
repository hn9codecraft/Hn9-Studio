<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Script;
use App\Models\ScriptReviewEvent;
use App\Repositories\Contracts\ScriptReviewEventRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<ScriptReviewEvent>
 */
class ScriptReviewEventRepository extends BaseRepository implements ScriptReviewEventRepositoryInterface
{
    /**
     * @return Builder<ScriptReviewEvent>
     */
    protected function query(): Builder
    {
        return ScriptReviewEvent::query();
    }

    public function listForScript(Script $script, array $with = []): Collection
    {
        return $this->query()
            ->with($with)
            ->where('script_id', $script->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
