<?php

declare(strict_types=1);

namespace App\Events\Ads;

use App\Models\AdCampaign;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AdRendered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AdCampaign $campaign,
        public readonly AdPlacement $placement,
        public readonly AdImpression $impression,
    ) {}
}
