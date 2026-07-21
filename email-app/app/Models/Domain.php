<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Inbound email domain used for temporary mailbox generation.
 *
 * @property string $id
 * @property string $domain
 * @property string $display_name
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $allow_registration
 * @property bool $is_healthy
 * @property int $priority
 * @property int|null $max_mailboxes
 * @property int $retention_hours
 * @property Carbon|null $dns_verified_at
 * @property Carbon|null $last_health_check_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Inbox> $inboxes
 */
class Domain extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'domains';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'display_name',
        'description',
        'is_active',
        'is_public',
        'allow_registration',
        'is_healthy',
        'priority',
        'max_mailboxes',
        'retention_hours',
        'dns_verified_at',
        'last_health_check_at',
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
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'allow_registration' => 'boolean',
            'is_healthy' => 'boolean',
            'priority' => 'integer',
            'max_mailboxes' => 'integer',
            'retention_hours' => 'integer',
            'dns_verified_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the inboxes belonging to the domain.
     */
    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class);
    }

    /**
     * Scope a query to only include active domains.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to only include publicly visible domains.
     */
    #[Scope]
    protected function publiclyVisible(Builder $query): void
    {
        $query->where('is_public', true);
    }

    /**
     * Scope a query to domains that allow registration.
     */
    #[Scope]
    protected function registrationAllowed(Builder $query): void
    {
        $query->where('allow_registration', true);
    }

    /**
     * Scope a query to order domains by priority.
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('priority');
    }

    /**
     * Determine whether the domain is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Determine whether the domain is public.
     */
    public function isPublic(): bool
    {
        return $this->is_public;
    }

    /**
     * Determine whether the domain allows registration.
     */
    public function allowsRegistration(): bool
    {
        return $this->allow_registration;
    }

    /**
     * Determine whether the domain DNS has been verified.
     */
    public function isVerified(): bool
    {
        return $this->dns_verified_at !== null;
    }
}
