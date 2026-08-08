<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\UserServiceInterface;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class UserService implements UserServiceInterface
{
    public function __construct(private UserRepositoryInterface $users, private ActivityLoggerInterface $activity) {}

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->users->paginate($perPage, $filters);
    }

    public function getByUuid(string $uuid): User
    {
        return $this->users->findByUuidOrFail($uuid);
    }

    public function update(User $user, array $data): User
    {
        $user = $this->users->update($user, $data);

        $this->activity->log('user.updated', $user, null, 'User updated');

        return $user;
    }

    public function delete(User $user): bool
    {
        $deleted = $this->users->delete($user);

        if ($deleted) {
            $this->activity->log('user.deleted', $user, null, 'User deleted');
        }

        return $deleted;
    }

    public function restore(string $uuid): User
    {
        $user = $this->getByUuid($uuid);

        $user->restore();

        $this->activity->log('user.restored', $user, null, 'User restored');

        return $user->refresh();
    }
}
