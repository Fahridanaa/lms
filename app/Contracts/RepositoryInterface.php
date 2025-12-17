<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    /**
     * Get all records
     */
    public function all(array $relations = []): Collection;

    /**
     * Find record by ID
     */
    public function find(int $id, array $relations = []): ?Model;

    /**
     * Find record by ID or fail
     */
    public function findOrFail(int $id, array $relations = []): Model;

    /**
     * Find record by specific column
     */
    public function findBy(string $column, mixed $value, array $relations = []): ?Model;

    /**
     * Get records matching criteria
     */
    public function where(string $column, mixed $value, array $relations = []): Collection;

    /**
     * Create new record
     */
    public function create(array $data): Model;

    /**
     * Update existing record
     */
    public function update(int $id, array $data): Model;

    /**
     * Delete record
     */
    public function delete(int $id): bool;

    /**
     * Get paginated results
     */
    public function paginate(int $perPage = 15, array $relations = []);

    /**
     * Count records
     */
    public function count(array $conditions = []): int;
}
