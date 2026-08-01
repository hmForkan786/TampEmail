<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliateAttributionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cookie/click-based referral attribution window for a visitor.
 *
 * @property string $id
 * @property string $affiliate_profile_id
 * @property string $visitor_token_hash
 * @property string $referral_code
 * @property string|null $landing_url
 * @property string|null $referrer_url
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon $expires_at
 * @property string|null $converted_user_id
 * @property Carbon|null $converted_at
 * @property AffiliateAttributionStatus $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AffiliateProfile|null $profile
 * @property-read User|null $convertedUser
 * @property-read Collection<int, AffiliateConversion> $conversions
 */
class AffiliateAttribution extends BaseModel
{
    protected $table = 'affiliate_attributions';

    protected $fillable = [
        'affiliate_profile_id',
        'visitor_token_hash',
        'referral_code',
        'landing_url',
        'referrer_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'ip_hash',
        'user_agent_hash',
        'first_seen_at',
        'last_seen_at',
        'expires_at',
        'converted_user_id',
        'converted_at',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => AffiliateAttributionStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'converted_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /** @return BelongsTo<AffiliateProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    /** @return HasMany<AffiliateConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class, 'attribution_id');
    }

    public function isActive(): bool
    {
        return $this->status === AffiliateAttributionStatus::Active;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->expires_at->isBefore($now);
    }
}
