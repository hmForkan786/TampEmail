<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\DTOs\Ads\AdAudienceContext;
use App\Enums\AdAudience;
use App\Enums\AdDevice;
use App\Enums\AdCampaignPurpose;
use App\Models\AdCampaign;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;

final class AdTargetingEvaluator
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function buildContext(?User $user, ?string $country = null, ?AdDevice $device = null, ?string $language = null, ?string $theme = null, ?string $sessionHash = null, ?string $ipHash = null): AdAudienceContext
    {
        if ($user === null) {
            return AdAudienceContext::guest($country, $device, $language, $theme, $sessionHash, $ipHash);
        }

        $adsVisible = $this->entitlements->allows($user, 'ads.visible');
        $plan = $this->entitlements->effectivePlan($user);
        $isPremium = $plan !== null && $plan->slug !== EntitlementService::FREE_PLAN_SLUG;

        return new AdAudienceContext(
            user: $user,
            country: $country,
            device: $device,
            language: $language,
            theme: $theme,
            sessionHash: $sessionHash,
            ipHash: $ipHash,
            isAuthenticated: true,
            adsVisible: $adsVisible,
            isPremium: $isPremium,
        );
    }

    public function matches(AdCampaign $campaign, AdAudienceContext $context): bool
    {
        if (! $this->matchesAudience($campaign->audience(), $context)) {
            return false;
        }

        $targeting = $campaign->targeting ?? [];

        if (! $this->matchesList($targeting['countries'] ?? null, $context->country)) {
            return false;
        }

        if (! $this->matchesList($targeting['devices'] ?? null, $context->device?->value)) {
            return false;
        }

        if (! $this->matchesList($targeting['languages'] ?? null, $context->language)) {
            return false;
        }

        if (! $this->matchesList($targeting['themes'] ?? null, $context->theme)) {
            return false;
        }

        return true;
    }

    /**
     * Monetization campaigns require ads.visible (and optional premium_hide).
     * Promotion campaigns skip the entitlement gate and use targeting only.
     */
    public function passesCommercialGate(AdCampaign $campaign, AdAudienceContext $context): bool
    {
        $purpose = $campaign->purpose ?? AdCampaignPurpose::Monetization;
        if (! $purpose->requiresAdsEntitlement()) {
            return true;
        }

        if (! (bool) config('ads.premium_hide', true)) {
            return true;
        }

        if ($context->user === null) {
            return true;
        }

        return $context->adsVisible;
    }

    private function matchesAudience(AdAudience $audience, AdAudienceContext $context): bool
    {
        return match ($audience) {
            AdAudience::All => true,
            AdAudience::AnonymousOnly => ! $context->isAuthenticated,
            AdAudience::LoggedInOnly => $context->isAuthenticated,
            AdAudience::FreeOnly => $context->isAuthenticated && ! $context->isPremium,
            AdAudience::PremiumExcluded => ! $context->isPremium,
        };
    }

    /**
     * @param  mixed  $allowed
     */
    private function matchesList(mixed $allowed, ?string $actual): bool
    {
        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        if ($actual === null || $actual === '') {
            return false;
        }

        $normalized = array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $allowed,
        );

        return in_array(strtolower($actual), $normalized, true);
    }
}
