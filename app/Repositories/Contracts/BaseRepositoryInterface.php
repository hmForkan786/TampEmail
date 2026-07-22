<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared contract for common repository CRUD operations.
 *
 * Module-specific methods such as paginate() and unique lookups remain on
 * each concrete repository interface to preserve Filter DTO type safety.
 *
 * @template TModel of Model
 * @template TCreateData of object
 * @template TUpdateData of object
 */
interface BaseRepositoryInterface
{
    /**
     * Persist a new record.
     *
     * @param TCreateData $data Validated creation data.
     *
     * @return TModel The created record.
     */
    public function create(object $data): Model;

    /**
     * Update an existing record with partial data.
     *
     * @param TModel      $model The record to update.
     * @param TUpdateData $data  Validated update data.
     *
     * @return TModel The updated record.
     */
    public function update(Model $model, object $data): Model;

    /**
     * Delete the given record.
     *
     * @param TModel $model The record to delete.
     *
     * @return bool Whether the record was deleted.
     */
    public function delete(Model $model): bool;

    /**
     * Find a record by its identifier.
     *
     * @param string $id Record identifier.
     *
     * @return TModel|null The matching record, if found.
     */
    public function findById(string $id): ?Model;
}
