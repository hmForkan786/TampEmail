<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\DTOs\Ads\AdAudienceContext;
use App\DTOs\Ads\AdDecision;
use App\Enums\AdDevice;
use App\Exceptions\Ads\UnknownAdProviderException;
use App\Exceptions\Ads\UnsafeAdContentException;
use App\Models\AdPlacement;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Central ad decision engine.
 *
 * Order: Emergency Stop → Platform enable → Placement → Campaign → Provider → Render.
 * Commercial entitlement is applied per campaign purpose inside the selector.
 */
final class AdDecisionEngine
{
    public function __construct(
        private readonly AdEmergencyStopService $emergencyStop,
        private readonly AdTargetingEvaluator $targeting,
        private readonly AdCampaignSelector $selector,
        private readonly AdProviderRegistry $providers,
        private readonly AdStatisticsService $statistics,
    ) {}

    public function decide(
        string $placementKey,
        ?User $user = null,
        ?string $country = null,
        ?AdDevice $device = null,
        ?string $language = null,
        ?string $theme = null,
        ?string $sessionHash = null,
        ?string $ipHash = null,
        bool $recordImpression = true,
    ): AdDecision {
        $placementKey = trim($placementKey);

        if ($this->emergencyStop->isStopped()) {
            return AdDecision::empty($placementKey, 'emergency_stop');
        }

        if (! (bool) config('ads.enabled', true)) {
            return AdDecision::empty($placementKey, 'ads_disabled');
        }

        $placement = AdPlacement::query()
            ->where('key', $placementKey)
            ->where('is_active', true)
            ->first();

        if ($placement === null) {
            return AdDecision::empty($placementKey, 'placement_not_found');
        }

        $context = $this->targeting->buildContext(
            $user,
            $country,
            $device,
            $language,
            $theme,
            $sessionHash,
            $ipHash,
        );

        $campaign = $this->selector->select($placement, $context);
        if ($campaign === null) {
            return AdDecision::empty($placementKey, 'no_eligible_campaign');
        }

        try {
            $provider = $this->providers->get($campaign->provider->value);
            if (! $provider->isAvailable() && $campaign->provider->value === 'google_adsense') {
                // AdSense may still render with per-campaign publisher_id.
                if (! $provider->validateConfig($campaign->provider_config ?? [])) {
                    return AdDecision::empty($placementKey, 'provider_unavailable');
                }
            }

            $render = $provider->render($campaign, $placement);
            if (! $render->safe) {
                return AdDecision::empty($placementKey, 'unsafe_render');
            }
        } catch (UnknownAdProviderException|UnsafeAdContentException $e) {
            Log::warning('ads.render_failed', [
                'campaign_id' => $campaign->getKey(),
                'provider' => $campaign->provider->value,
                'message' => $e->getMessage(),
            ]);

            return AdDecision::empty($placementKey, 'render_failed');
        }

        $impressionId = null;
        if ($recordImpression) {
            $impression = $this->statistics->recordImpression($campaign, $placement, $context);
            $impressionId = $impression->getKey();
        }

        return new AdDecision(
            placementKey: $placementKey,
            show: true,
            reason: 'selected',
            campaignId: $campaign->getKey(),
            placementId: $placement->getKey(),
            provider: $campaign->provider->value,
            purpose: $campaign->purpose->value,
            impressionId: $impressionId,
            render: $render,
        );
    }
}
