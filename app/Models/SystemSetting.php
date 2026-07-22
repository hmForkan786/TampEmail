<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ValueType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * Global application configuration stored as flexible key-value settings.
 *
 * @property string $id
 * @property string $category
 * @property string $key
 * @property mixed $value
 * @property ValueType $value_type
 * @property string|null $description
 * @property bool $is_public
 * @property bool $is_editable
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SystemSetting extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'system_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category',
        'key',
        'value',
        'value_type',
        'description',
        'is_public',
        'is_editable',
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
            'value' => 'array',
            'is_public' => 'boolean',
            'is_editable' => 'boolean',
            'metadata' => 'array',
        ]);
    }

    /**
     * Scope a query to publicly visible settings.
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_public', true);
    }

    /**
     * Scope a query to editable settings.
     */
    #[Scope]
    protected function editable(Builder $query): void
    {
        $query->where('is_editable', true);
    }

    /**
     * Scope a query to settings in the given category.
     */
    #[Scope]
    protected function category(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * Determine whether the setting is publicly visible.
     */
    public function isPublic(): bool
    {
        return $this->is_public;
    }

    /**
     * Determine whether the setting is editable.
     */
    public function isEditable(): bool
    {
        return $this->is_editable;
    }

    /**
     * Get the stored setting value.
     */
    public function value(): mixed
    {
        return $this->getAttribute('value');
    }
}
