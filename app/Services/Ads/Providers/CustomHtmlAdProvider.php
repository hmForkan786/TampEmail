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

final class CustomHtmlAdProvider implements AdProvider
{
    public function __construct(private readonly AdContentSanitizer $sanitizer) {}

    public function name(): string
    {
        return AdProviderName::CustomHtml->value;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function validateConfig(array $config): bool
    {
        $html = trim((string) ($config['html'] ?? ''));

        return $html !== '' && $this->sanitizer->sanitizeHtml($html) !== '';
    }

    public function render(AdCampaign $campaign, AdPlacement $placement): AdRenderPayload
    {
        $config = $campaign->provider_config ?? [];
        $html = $this->sanitizer->sanitizeHtml((string) ($config['html'] ?? ''));
        if ($html === '') {
            throw new UnsafeAdContentException('Custom HTML rendered empty after sanitization.');
        }

        return new AdRenderPayload(
            type: $this->name(),
            safe: true,
            data: [
                'markup' => $html,
                'placement_key' => $placement->key,
            ],
        );
    }
}
