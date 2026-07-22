<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTarget;
use App\Enums\AnnouncementType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * Global announcement, alert, or maintenance notice for the application.
 *
 * @property string $id
 * @property string $title
 * @property string $slug
 * @property string $content
 * @property AnnouncementType $type
 * @property AnnouncementTarget $target
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $dismissible
 * @property int $priority
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Announcement extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'announcements';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'content',
        'type',
        'target',
        'is_active',
        'starts_at',
        'ends_at',
        'dismissible',
        'priority',
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
            'type' => AnnouncementType::class,
            'target' => AnnouncementTarget::class,
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'dismissible' => 'boolean',
            'priority' => 'integer',
            'metadata' => 'array',
        ]);
    }

    /**
     * Scope a query to only include active announcements.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to announcements of the given type.
     */
    #[Scope]
    protected function type(Builder $query, AnnouncementType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Scope a query to announcements for the given target audience.
     */
    #[Scope]
    protected function target(Builder $query, AnnouncementTarget $target): void
    {
        $query->where('target', $target);
    }

    /**
     * Scope a query to announcements active within the current time window.
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }

    /**
     * Determine whether the announcement is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Determine whether the announcement can be dismissed by users.
     */
    public function isDismissible(): bool
    {
        return $this->dismissible;
    }

    /**
     * Determine whether the announcement is active within the current time window.
     */
    public function isCurrent(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }
}
