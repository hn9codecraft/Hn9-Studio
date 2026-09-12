<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected function query(): Builder
    {
        return User::query();
    }

    public function findByUuidWithTrashedOrFail(string $uuid, array $with = []): User
    {
        return User::withTrashed()->with($with)->where('uuid', $uuid)->firstOrFail();
    }

    protected function filterable(): array
    {
        return ['status', 'role'];
    }

    public function paginate(int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        return parent::paginate($perPage, $filters, $with);
    }
}
