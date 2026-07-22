<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Base Eloquent model for UUID-backed domain entities.
 *
 * All application models with UUID primary keys should extend this class
 * to inherit consistent key configuration, UUID generation, and timestamp casting.
 */
abstract class BaseModel extends Model
{
    use HasUuid;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model's primary key is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
