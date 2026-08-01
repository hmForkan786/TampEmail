<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class AdCampaignPlacement extends Pivot
{
    use HasUuid;

    protected $table = 'ad_campaign_placement';

    public $incrementing = false;

    protected $keyType = 'string';
}
