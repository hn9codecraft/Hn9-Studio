<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Base Eloquent repository. Concrete repositories inherit generic CRUD and
 * lookup behaviour and only declare any bespoke queries.
 *
 * This is the sole layer permitted to touch Eloquent/the database. It contains
 * no business rules — those live in services.
 *
 * @template TModel of Model
 *
 * @implements RepositoryInterface<TModel>
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * A fresh query builder for the managed model. Implemented per repository
     * so the concrete model type propagates through every query — this keeps
     * the generic return types precise for static analysis.
     *
     * @return Builder<TModel>
     */
    abstract protected function query(): Builder;

    /**
     * Columns that may be filtered on via the `$filters` argument. Child
     * repositories override to opt columns in; unknown keys are ignored.
     *
     * @return list<string>
     */
    protected function filterable(): array
    {
        return [];
    }

    /**
     * Apply whitelisted equality filters to a query.
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<TModel>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($this->filterable() as $column) {
            if (array_key_exists($column, $filters) && $filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }

    /**
     * @return Collection<int, Model>
     */
    public function all(array $with = []): Collection
    {
        return $this->query()->with($with)->latest('id')->get();
    }

    public function paginate(int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        return $this->applyFilters($this->query()->with($with), $filters)
            ->latest('id')
            ->paginate($perPage);
    }

    public function find(int $id, array $with = []): ?Model
    {
        return $this->query()->with($with)->find($id);
    }

    public function findOrFail(int $id, array $with = []): Model
    {
        return $this->query()->with($with)->findOrFail($id);
    }

    public function findByUuid(string $uuid, array $with = []): ?Model
    {
        return $this->query()->with($with)->where('uuid', $uuid)->first();
    }

    public function findByUuidOrFail(string $uuid, array $with = []): Model
    {
        return $this->query()->with($with)->where('uuid', $uuid)->firstOrFail();
    }

    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes)->save();

        return $model->refresh();
    }

    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }
}
