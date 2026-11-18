<?php

declare(strict_types=1);

namespace App\Services\Ads\Providers;

use App\Contracts\Ads\AdProvider;
use App\DTOs\Ads\AdRenderPayload;
use App\Enums\AdPromotionKind;
use App\Enums\AdProviderName;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Services\Ads\AdContentSanitizer;

/**
 * Internal promotion engine adapter (upgrade, coupon, maintenance, etc.).
 */
final class HouseAdsProvider implements AdProvider
{
    public function __construct(private readonly AdContentSanitizer $sanitizer) {}

    public function name(): string
    {
        return AdProviderName::HouseAds->value;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function validateConfig(array $config): bool
    {
        $headline = trim((string) ($config['headline'] ?? ''));
        $ctaUrl = (string) ($config['cta_url'] ?? '');

        if ($headline === '' || $ctaUrl === '') {
            return false;
        }

        try {
            $this->sanitizer->assertSafeUrl($ctaUrl);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function render(AdCampaign $campaign, AdPlacement $placement): AdRenderPayload
    {
        $config = $campaign->provider_config ?? [];
        $headline = trim(strip_tags((string) ($config['headline'] ?? $campaign->name)));
        $body = $this->sanitizer->sanitizeHtml((string) ($config['body'] ?? ''));
        $ctaLabel = trim(strip_tags((string) ($config['cta_label'] ?? 'Learn more')));
        $ctaUrl = $this->sanitizer->assertSafeUrl((string) ($config['cta_url'] ?? '/'));
        $kind = $campaign->promotion_kind?->value
            ?? (string) ($config['promotion_kind'] ?? AdPromotionKind::Generic->value);

        return new AdRenderPayload(
            type: $this->name(),
            safe: true,
            data: [
                'headline' => $headline,
                'body' => $body,
                'cta_label' => $ctaLabel !== '' ? $ctaLabel : 'Learn more',
                'cta_url' => $ctaUrl,
                'promotion_kind' => $kind,
                'placement_key' => $placement->key,
            ],
        );
    }
}
