<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * UUID-backed pivot linking plans to their feature entitlements.
 *
 * @property string $id
 * @property string $feature_id
 * @property string $plan_id
 * @property array<string, mixed>|null $feature_value
 */
class FeaturePlan extends Pivot
{
    use HasUuid;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'feature_plan';

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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'feature_id',
        'plan_id',
        'feature_value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature_value' => 'array',
        ];
    }
}
