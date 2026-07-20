<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Automatically assigns a UUID primary key on model creation.
 */
trait HasUuid
{
    /**
     * Boot the HasUuid trait for a model.
     */
    protected static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            $key = $model->getKeyName();

            if ($model->{$key} === null || $model->{$key} === '') {
                $model->{$key} = (string) Str::uuid();
            }
        });
    }
}
