<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $ad_campaign_id
 * @property string $ad_placement_id
 * @property string|null $ad_impression_id
 * @property string|null $user_id
 * @property string|null $session_hash
 * @property string|null $country
 * @property string|null $device
 * @property string|null $language
 * @property string|null $ip_hash
 * @property string|null $destination_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdClick extends BaseModel
{
    protected $table = 'ad_clicks';

    protected $fillable = [
        'ad_campaign_id',
        'ad_placement_id',
        'ad_impression_id',
        'user_id',
        'session_hash',
        'country',
        'device',
        'language',
        'ip_hash',
        'destination_url',
    ];

    /** @return BelongsTo<AdCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    /** @return BelongsTo<AdPlacement, $this> */
    public function placement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class, 'ad_placement_id');
    }

    /** @return BelongsTo<AdImpression, $this> */
    public function impression(): BelongsTo
    {
        return $this->belongsTo(AdImpression::class, 'ad_impression_id');
    }
}
