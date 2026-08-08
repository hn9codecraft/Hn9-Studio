<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserServiceInterface
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getByUuid(string $uuid): User;

    public function update(User $user, array $data): User;

    public function delete(User $user): bool;

    public function restore(string $uuid): User;
}
