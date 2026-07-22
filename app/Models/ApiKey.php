<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Hashed API credential for authenticated user access.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property list<string>|null $permissions
 * @property int $rate_limit_per_minute
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ApiRequestLog> $requestLogs
 */
class ApiKey extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'api_keys';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'key_hash',
        'key_prefix',
        'permissions',
        'rate_limit_per_minute',
        'last_used_at',
        'expires_at',
        'revoked_at',
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
            'permissions' => 'array',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the user that owns the API key.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the API request logs for the key.
     */
    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    /**
     * Scope a query to only include active (non-revoked) API keys.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Scope a query to only include expired API keys.
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope a query to only include available API keys.
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Determine whether the API key is active (not revoked).
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Determine whether the API key has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }

    /**
     * Determine whether the API key is available for use.
     */
    public function isAvailable(): bool
    {
        return $this->isActive() && ! $this->isExpired();
    }

    /**
     * Determine whether the API key grants the given permission.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->permissions === null) {
            return false;
        }

        return in_array($permission, $this->permissions, true);
    }
}
