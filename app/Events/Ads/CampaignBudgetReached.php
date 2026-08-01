<?php

declare(strict_types=1);

namespace App\Events\Ads;

use App\Models\AdCampaign;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CampaignBudgetReached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly AdCampaign $campaign) {}
}
