<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Named surface where ads or promotions may render.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int $display_order
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AdCampaign> $campaigns
 */
class AdPlacement extends BaseModel
{
    use SoftDeletes;

    protected $table = 'ad_placements';

    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
        'display_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'metadata' => 'array',
        ]);
    }

    /** @return BelongsToMany<AdCampaign, $this> */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(AdCampaign::class, 'ad_campaign_placement')
            ->using(\App\Models\Pivots\AdCampaignPlacement::class)
            ->withTimestamps();
    }

    /** @return HasMany<AdImpression, $this> */
    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class);
    }
}
