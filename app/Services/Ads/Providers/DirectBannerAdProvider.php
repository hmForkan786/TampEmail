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

final class DirectBannerAdProvider implements AdProvider
{
    public function __construct(private readonly AdContentSanitizer $sanitizer) {}

    public function name(): string
    {
        return AdProviderName::DirectBanner->value;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function validateConfig(array $config): bool
    {
        try {
            $this->sanitizer->assertSafeUrl((string) ($config['image_url'] ?? ''), allowRelative: false);
            $this->sanitizer->assertSafeUrl((string) ($config['click_url'] ?? ''));

            return true;
        } catch (UnsafeAdContentException) {
            return false;
        }
    }

    public function render(AdCampaign $campaign, AdPlacement $placement): AdRenderPayload
    {
        $config = $campaign->provider_config ?? [];
        $imageUrl = $this->sanitizer->assertSafeUrl((string) ($config['image_url'] ?? ''), allowRelative: false);
        $clickUrl = $this->sanitizer->assertSafeUrl((string) ($config['click_url'] ?? ''));
        $alt = trim(strip_tags((string) ($config['alt'] ?? $campaign->name)));

        return new AdRenderPayload(
            type: $this->name(),
            safe: true,
            data: [
                'image_url' => $imageUrl,
                'click_url' => $clickUrl,
                'alt' => $alt !== '' ? $alt : 'Advertisement',
                'placement_key' => $placement->key,
            ],
        );
    }
}
