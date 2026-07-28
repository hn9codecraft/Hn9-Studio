<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Base contract every repository fulfils. Repositories are the only layer that
 * touches Eloquent/the database; services depend on these interfaces, never on
 * concrete implementations.
 *
 * @template TModel of Model
 */
interface RepositoryInterface
{
    /**
     * All records, optionally eager-loading the given relations. Item typing is
     * intentionally the base model; use the per-repository typed accessors when
     * a concrete element type is required.
     *
     * @param  list<string>  $with
     * @return Collection<int, Model>
     */
    public function all(array $with = []): Collection;

    /**
     * A single page of records.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;

    /**
     * Find by primary key, or null.
     *
     * @param  list<string>  $with
     * @return TModel|null
     */
    public function find(int $id, array $with = []): ?Model;

    /**
     * Find by primary key, or throw ModelNotFoundException.
     *
     * @param  list<string>  $with
     * @return TModel
     */
    public function findOrFail(int $id, array $with = []): Model;

    /**
     * Find by public UUID, or null.
     *
     * @param  list<string>  $with
     * @return TModel|null
     */
    public function findByUuid(string $uuid, array $with = []): ?Model;

    /**
     * Find by public UUID, or throw ModelNotFoundException.
     *
     * @param  list<string>  $with
     * @return TModel
     */
    public function findByUuidOrFail(string $uuid, array $with = []): Model;

    /**
     * Persist a new record from attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * Update an existing record from attributes.
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model;

    /**
     * Soft/hard delete a record.
     *
     * @param  TModel  $model
     */
    public function delete(Model $model): bool;
}
