<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\DTOs\Ads\AdAudienceContext;
use App\Enums\AdCampaignStatus;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use Illuminate\Support\Collection;

final class AdCampaignSelector
{
    public function __construct(private readonly AdTargetingEvaluator $targeting) {}

    public function select(AdPlacement $placement, AdAudienceContext $context): ?AdCampaign
    {
        /** @var Collection<int, AdCampaign> $candidates */
        $candidates = $placement->campaigns()
            ->where('status', AdCampaignStatus::Active->value)
            ->orderBy('priority')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($candidates as $campaign) {
            if (! $campaign->isWithinSchedule()) {
                continue;
            }

            if ($campaign->hasReachedLimits()) {
                continue;
            }

            if (! $this->targeting->passesCommercialGate($campaign, $context)) {
                continue;
            }

            if (! $this->targeting->matches($campaign, $context)) {
                continue;
            }

            return $campaign;
        }

        return null;
    }
}
