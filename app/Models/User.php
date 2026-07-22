<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Registered platform user with authentication and ownership context.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar
 * @property string $timezone
 * @property string $locale
 * @property UserStatus $status
 * @property PlatformRole $platform_role
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read UserPreference|null $preference
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, Inbox> $inboxes
 * @property-read Collection<int, ApiKey> $apiKeys
 * @property-read Collection<int, AuditLog> $auditLogs
 * @property-read Collection<int, ApiRequestLog> $apiRequestLogs
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuid;
    use Notifiable;
    use SoftDeletes;

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
        'name',
        'email',
        'password',
        'avatar',
        'timezone',
        'locale',
        'status',
        'platform_role',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'platform_role' => PlatformRole::class,
        ];
    }

    /**
     * Get the user's application preferences.
     *
     * @return HasOne<UserPreference, $this>
     */
    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * Get the user's current subscription.
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Get the inboxes owned by the user.
     */
    public function inboxes(): HasMany
    {
        return $this->hasMany(Inbox::class);
    }

    /**
     * Get the API keys owned by the user.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Get the audit logs attributed to the user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get the API request logs attributed to the user.
     */
    public function apiRequestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    /**
     * Scope a query to only include active users.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    /**
     * Scope a query to only include verified users.
     */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->whereNotNull('email_verified_at');
    }

    /**
     * Determine whether the user account is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Determine whether the user currently holds an active privileged platform role.
     *
     * True for verified operators and admins only. Ordinary users, inactive
     * lifecycle states, soft-deleted accounts, and unknown roles fail closed.
     */
    public function hasActivePlatformCapability(): bool
    {
        if ($this->trashed()) {
            return false;
        }

        if ($this->status !== UserStatus::Active) {
            return false;
        }

        $role = $this->resolvePlatformRole();

        return $role instanceof PlatformRole && $role->isPrivileged();
    }

    /**
     * Determine whether the user is a verified platform operator.
     *
     * Admins also satisfy operator capability. Unknown or missing roles fail closed.
     */
    public function isPlatformOperator(): bool
    {
        return $this->hasActivePlatformCapability();
    }

    /**
     * Determine whether the user is a verified platform administrator.
     *
     * Unknown or missing roles fail closed.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->hasActivePlatformCapability()
            && $this->resolvePlatformRole() === PlatformRole::Admin;
    }

    /**
     * Resolve the platform role from stored attributes without throwing on unknown values.
     */
    private function resolvePlatformRole(): ?PlatformRole
    {
        $raw = $this->attributes['platform_role'] ?? null;

        if ($raw instanceof PlatformRole) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return PlatformRole::tryFrom($raw);
    }

    /**
     * Determine whether the user has an active premium subscription.
     */
    public function isPremium(): bool
    {
        $subscription = $this->subscription;

        if ($subscription === null) {
            return false;
        }

        return $subscription->status === SubscriptionStatus::Active;
    }

    /**
     * Get the user's preferred language code.
     */
    public function preferredLanguage(): string
    {
        $preference = $this->preference()->first();

        if ($preference instanceof UserPreference) {
            return $preference->language;
        }

        return $this->locale;
    }

    /**
     * Get the user's preferred timezone identifier.
     */
    public function preferredTimezone(): string
    {
        $preference = $this->preference()->first();

        if ($preference instanceof UserPreference) {
            return $preference->timezone;
        }

        return $this->timezone;
    }
}
