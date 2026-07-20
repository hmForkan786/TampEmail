<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ValueType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Platform feature catalog entry for plan entitlements.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property ValueType $value_type
 * @property array<string, mixed>|null $default_value
 * @property bool $is_active
 * @property int $display_order
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Plan> $plans
 */
class Feature extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'features';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'value_type',
        'default_value',
        'is_active',
        'display_order',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'value_type' => ValueType::class,
            'default_value' => 'array',
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the plans that include this feature.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'feature_plan')
            ->withPivot('feature_value')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active features.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to order features by display order.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('display_order');
    }

    /**
     * Scope a query to features within the given category.
     */
    #[Scope]
    protected function category(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * Determine whether the feature is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Get the feature default value.
     */
    public function defaultValue(): mixed
    {
        return $this->default_value;
    }

    /**
     * Determine whether the feature has a default value configured.
     */
    public function hasDefaultValue(): bool
    {
        return $this->default_value !== null;
    }
}
