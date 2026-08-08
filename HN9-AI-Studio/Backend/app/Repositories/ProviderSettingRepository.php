<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ProviderSetting;
use App\Repositories\Contracts\ProviderSettingRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends BaseRepository<ProviderSetting>
 */
final class ProviderSettingRepository extends BaseRepository implements ProviderSettingRepositoryInterface
{
    protected function query(): Builder
    {
        return ProviderSetting::query();
    }
}
