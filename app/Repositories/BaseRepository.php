<?php

namespace App\Repositories;

use App\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;

    public function all(array $relations = []): Collection
    {
        $query = $this->model->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->get();
    }

    public function find(int $id, array $relations = []): ?Model
    {
        $query = $this->model->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    public function findOrFail(int $id, array $relations = []): Model
    {
        $query = $this->model->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->findOrFail($id);
    }

    public function findBy(string $column, mixed $value, array $relations = []): ?Model
    {
        $query = $this->model->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->where($column, $value)->first();
    }

    public function where(string $column, mixed $value, array $relations = []): Collection
    {
        $query = $this->model->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->where($column, $value)->get();
    }

    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Model
    {
        $record = $this->findOrFail($id);
        $record->update($data);

        return $record->fresh();
    }

    public function delete(int $id): bool
    {
        $record = $this->findOrFail($id);

        return $record->delete();
    }

    public function forceDelete(int $id): bool
    {
        $record = $this->findOrFail($id);

        return $record->forceDelete();
    }

    public function restore(int $id): bool
    {
        $query = $this->model->newQuery();

        // Check if model uses SoftDeletes
        if (method_exists($this->model, 'withTrashed')) {
            $query = $query->withTrashed();
        }

        $record = $query->findOrFail($id);

        return $record->restore();
    }

    public function paginate(int $perPage = 15, array $relations = [])
    {
        $query = $this->model->newQuery();

        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->paginate($perPage);
    }

    public function count(array $conditions = []): int
    {
        $query = $this->model->newQuery();

        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }
}
