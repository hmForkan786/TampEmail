<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AffiliateFraudDecision;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fraud/abuse signal recorded for an affiliate action (attribution, conversion, etc).
 *
 * @property string $id
 * @property string|null $affiliate_profile_id
 * @property string|null $conversion_id
 * @property string|null $attribution_id
 * @property string|null $referred_user_id
 * @property AffiliateFraudDecision $decision
 * @property array<int, string> $reason_codes
 * @property array<string, mixed>|null $context
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property-read AffiliateProfile|null $profile
 */
class AffiliateFraudFlag extends BaseModel
{
    protected $table = 'affiliate_fraud_flags';

    const UPDATED_AT = null;

    protected $fillable = [
        'affiliate_profile_id',
        'conversion_id',
        'attribution_id',
        'referred_user_id',
        'decision',
        'reason_codes',
        'context',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'decision' => AffiliateFraudDecision::class,
            'reason_codes' => 'array',
            'context' => 'array',
            'reviewed_at' => 'datetime',
        ]);
    }

    /** @return BelongsTo<AffiliateProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function requiresReview(): bool
    {
        return $this->decision === AffiliateFraudDecision::ManualReview;
    }
}
