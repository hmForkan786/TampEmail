<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Enums\AdCampaignStatus;
use App\Models\AdCampaign;
use App\Models\AdPlacement;

final class AdHealthCheckService
{
    public function __construct(
        private readonly AdEmergencyStopService $emergencyStop,
        private readonly AdProviderRegistry $providers,
        private readonly AdStatisticsService $statistics,
    ) {}

    /**
     * @return array{
     *     healthy: bool,
     *     enabled: bool,
     *     emergency_stop: bool,
     *     active_campaigns: int,
     *     active_placements: int,
     *     providers_registered: list<string>,
     *     providers_available: list<string>,
     *     statistics: array{impressions: int, clicks: int, ctr: float, revenue_minor: int}
     * }
     */
    public function check(): array
    {
        $activeCampaigns = AdCampaign::query()->where('status', AdCampaignStatus::Active->value)->count();
        $activePlacements = AdPlacement::query()->where('is_active', true)->count();
        $emergency = $this->emergencyStop->isStopped();
        $enabled = (bool) config('ads.enabled', true);

        $result = [
            'healthy' => true,
            'enabled' => $enabled,
            'emergency_stop' => $emergency,
            'active_campaigns' => $activeCampaigns,
            'active_placements' => $activePlacements,
            'providers_registered' => $this->providers->registeredProviders(),
            'providers_available' => $this->providers->availableProviders(),
            'statistics' => $this->statistics->summary(),
        ];

        // Emergency stop is intentional ops state — still "healthy" but flagged.
        // Unhealthy only when subsystem is enabled yet has zero placements configured.
        if ($enabled && ! $emergency && $activePlacements === 0) {
            $result['healthy'] = false;
            $result['reason'] = 'no_active_placements';
        }

        return $result;
    }
}
