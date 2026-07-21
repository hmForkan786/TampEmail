<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Shared Eloquent implementation for common repository CRUD operations.
 *
 * Module-specific lookups and Filter DTO pagination remain in concrete repositories.
 *
 * @template TModel of Model
 * @template TCreateData of object
 * @template TUpdateData of object
 *
 * @implements BaseRepositoryInterface<TModel, TCreateData, TUpdateData>
 */
abstract class BaseEloquentRepository implements BaseRepositoryInterface
{
    /**
     * Provide a fresh model instance for query construction.
     *
     * @return TModel
     */
    abstract protected function model(): Model;

    /**
     * Persist a new record.
     *
     * @param TCreateData $data Validated creation data.
     *
     * @return TModel The created record.
     */
    public function create(object $data): Model
    {
        return $this->model()->newQuery()->create($this->toAttributes($data));
    }

    /**
     * Update an existing record with partial data.
     *
     * @param TModel      $model The record to update.
     * @param TUpdateData $data  Validated update data.
     *
     * @return TModel The updated record.
     */
    public function update(Model $model, object $data): Model
    {
        $model->update($this->toAttributes($data));

        return $model->refresh();
    }

    /**
     * Delete the given record.
     *
     * @param TModel $model The record to delete.
     *
     * @return bool Whether the record was deleted.
     */
    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * Find a record by its identifier.
     *
     * @param string $id Record identifier.
     *
     * @return TModel|null The matching record, if found.
     */
    public function findById(string $id): ?Model
    {
        return $this->model()->newQuery()->find($id);
    }

    /**
     * Convert a DTO-like data object into mass-assignment attributes.
     *
     * @return array<string, mixed>
     */
    protected function toAttributes(object $data): array
    {
        if (! method_exists($data, 'toArray')) {
            throw new InvalidArgumentException(
                sprintf('Data object of type [%s] must provide a toArray() method.', $data::class)
            );
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $data->toArray();

        return $attributes;
    }
}
