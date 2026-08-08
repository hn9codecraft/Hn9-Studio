<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface
{
    /** @return Collection<int, User> */
    public function all(array $with = []);

    public function paginate(int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;

    public function findByUuidOrFail(string $uuid, array $with = []): User;

    public function create(array $attributes): User;

    public function update(User $user, array $attributes): User;

    public function delete(User $user): bool;
}
