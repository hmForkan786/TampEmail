<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Manual revenue ledger row for ad monetization reporting.
 *
 * @property string $id
 * @property string|null $ad_campaign_id
 * @property string|null $provider
 * @property Carbon $earned_on
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $source
 * @property string|null $notes
 * @property string|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdRevenueEntry extends BaseModel
{
    protected $table = 'ad_revenue_entries';

    protected $fillable = [
        'ad_campaign_id',
        'provider',
        'earned_on',
        'amount_minor',
        'currency',
        'source',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'earned_on' => 'date',
            'amount_minor' => 'integer',
        ]);
    }

    /** @return BelongsTo<AdCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }
}
