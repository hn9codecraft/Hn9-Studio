<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\User;

/**
 * @extends RepositoryInterface<User>
 */
interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * Find by public UUID including soft-deleted rows, or throw.
     *
     * @param  list<string>  $with
     */
    public function findByUuidWithTrashedOrFail(string $uuid, array $with = []): User;
}
