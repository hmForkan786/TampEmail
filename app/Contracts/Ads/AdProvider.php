<?php

declare(strict_types=1);

namespace App\Contracts\Ads;

use App\DTOs\Ads\AdRenderPayload;
use App\Models\AdCampaign;
use App\Models\AdPlacement;

/**
 * Provider-neutral advertisement adapter.
 *
 * Adapters never decide eligibility — they only turn campaign config into a
 * safe render payload for an already-selected campaign.
 */
interface AdProvider
{
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * @param  array<string, mixed>  $config
     */
    public function validateConfig(array $config): bool;

    public function render(AdCampaign $campaign, AdPlacement $placement): AdRenderPayload;
}
