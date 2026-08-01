<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Distinguishes paid/network monetization from internal promotion campaigns.
 *
 * Monetization respects ads.visible / premium-hide.
 * Promotion (upgrade, coupon, maintenance, etc.) follows targeting only.
 */
enum AdCampaignPurpose: string
{
    case Monetization = 'monetization';
    case Promotion = 'promotion';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Monetization->value => 'Monetization (external ads)',
            self::Promotion->value => 'Internal promotion',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public function requiresAdsEntitlement(): bool
    {
        return $this === self::Monetization;
    }
}
