<?php

declare(strict_types=1);

namespace App\Services\Ads\Providers;

use App\Contracts\Ads\AdProvider;
use App\DTOs\Ads\AdRenderPayload;
use App\Enums\AdProviderName;
use App\Exceptions\Ads\UnsafeAdContentException;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Services\Ads\AdContentSanitizer;

final class GoogleAdSenseProvider implements AdProvider
{
    public function __construct(private readonly AdContentSanitizer $sanitizer) {}

    public function name(): string
    {
        return AdProviderName::GoogleAdSense->value;
    }

    public function isAvailable(): bool
    {
        $default = config('ads.google_adsense.publisher_id');

        return is_string($default) && $default !== '';
    }

    public function validateConfig(array $config): bool
    {
        try {
            $publisher = (string) ($config['publisher_id'] ?? config('ads.google_adsense.publisher_id', ''));
            $slot = (string) ($config['slot_id'] ?? '');
            $this->sanitizer->assertPublisherId($publisher);
            $this->sanitizer->assertSlotId($slot);

            return true;
        } catch (UnsafeAdContentException) {
            return false;
        }
    }

    public function render(AdCampaign $campaign, AdPlacement $placement): AdRenderPayload
    {
        $config = $campaign->provider_config ?? [];
        $publisher = (string) ($config['publisher_id'] ?? config('ads.google_adsense.publisher_id', ''));
        $slot = (string) ($config['slot_id'] ?? '');
        $responsive = (bool) ($config['responsive'] ?? config('ads.google_adsense.responsive', true));

        $publisher = $this->sanitizer->assertPublisherId($publisher);
        $slot = $this->sanitizer->assertSlotId($slot);

        return new AdRenderPayload(
            type: $this->name(),
            safe: true,
            data: [
                'publisher_id' => $publisher,
                'slot_id' => $slot,
                'responsive' => $responsive,
                'placement_key' => $placement->key,
            ],
        );
    }
}
