<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdAudience;
use App\Enums\AdCampaignPurpose;
use App\Enums\AdCampaignStatus;
use App\Enums\AdPromotionKind;
use App\Enums\AdProviderName;
use App\Models\Pivots\AdCampaignPlacement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Platform-managed advertisement or internal promotion campaign.
 *
 * @property string $id
 * @property string $name
 * @property string $provider
 * @property string $purpose
 * @property string|null $promotion_kind
 * @property string $status
 * @property int $priority
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $daily_budget
 * @property int|null $max_impressions
 * @property int|null $max_clicks
 * @property int $impressions_today
 * @property int $impressions_total
 * @property int $clicks_today
 * @property int $clicks_total
 * @property Carbon|null $budget_day
 * @property array<string, mixed>|null $targeting
 * @property array<string, mixed>|null $provider_config
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AdPlacement> $placements
 * @property-read Collection<int, AdImpression> $impressions
 * @property-read Collection<int, AdClick> $clicks
 */
class AdCampaign extends BaseModel
{
    use SoftDeletes;

    protected $table = 'ad_campaigns';

    protected $fillable = [
        'name',
        'provider',
        'purpose',
        'promotion_kind',
        'status',
        'priority',
        'starts_at',
        'ends_at',
        'daily_budget',
        'max_impressions',
        'max_clicks',
        'impressions_today',
        'impressions_total',
        'clicks_today',
        'clicks_total',
        'budget_day',
        'targeting',
        'provider_config',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'provider' => AdProviderName::class,
            'purpose' => AdCampaignPurpose::class,
            'promotion_kind' => AdPromotionKind::class,
            'status' => AdCampaignStatus::class,
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'daily_budget' => 'integer',
            'max_impressions' => 'integer',
            'max_clicks' => 'integer',
            'impressions_today' => 'integer',
            'impressions_total' => 'integer',
            'clicks_today' => 'integer',
            'clicks_total' => 'integer',
            'budget_day' => 'date',
            'targeting' => 'array',
            'provider_config' => 'array',
        ]);
    }

    /** @return BelongsToMany<AdPlacement, $this> */
    public function placements(): BelongsToMany
    {
        return $this->belongsToMany(AdPlacement::class, 'ad_campaign_placement')
            ->using(AdCampaignPlacement::class)
            ->withTimestamps();
    }

    /** @return HasMany<AdImpression, $this> */
    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class);
    }

    /** @return HasMany<AdClick, $this> */
    public function clicks(): HasMany
    {
        return $this->hasMany(AdClick::class);
    }

    /** @return HasMany<AdRevenueEntry, $this> */
    public function revenueEntries(): HasMany
    {
        return $this->hasMany(AdRevenueEntry::class);
    }

    public function audience(): AdAudience
    {
        $raw = $this->targeting['audience'] ?? AdAudience::All->value;

        return AdAudience::tryFrom((string) $raw) ?? AdAudience::All;
    }

    public function isWithinSchedule(?Carbon $now = null): bool
    {
        $now ??= now();

        if ($this->starts_at !== null && $this->starts_at->isAfter($now)) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->isBefore($now)) {
            return false;
        }

        return true;
    }

    public function hasReachedLimits(): bool
    {
        if ($this->max_impressions !== null && $this->impressions_total >= $this->max_impressions) {
            return true;
        }

        if ($this->max_clicks !== null && $this->clicks_total >= $this->max_clicks) {
            return true;
        }

        if ($this->daily_budget !== null && $this->impressions_today >= $this->daily_budget) {
            return true;
        }

        return false;
    }
}
