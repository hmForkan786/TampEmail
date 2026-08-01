<?php

declare(strict_types=1);

namespace App\Enums;

enum AdProviderName: string
{
    case GoogleAdSense = 'google_adsense';
    case DirectBanner = 'direct_banner';
    case HouseAds = 'house_ads';
    case CustomHtml = 'custom_html';
    case Adsterra = 'adsterra';
    case MediaNet = 'media_net';
    case Ezoic = 'ezoic';
    case PropellerAds = 'propeller_ads';

    public static function normalize(string $provider): string
    {
        $slug = strtolower(trim($provider));

        return match ($slug) {
            'adsense', 'google', 'google-adsense' => self::GoogleAdSense->value,
            'direct', 'banner', 'direct-banner' => self::DirectBanner->value,
            'house', 'internal', 'promotion', 'house-ads' => self::HouseAds->value,
            'html', 'custom', 'custom-html' => self::CustomHtml->value,
            'medianet', 'media-net' => self::MediaNet->value,
            'propeller', 'propellerads' => self::PropellerAds->value,
            default => $slug,
        };
    }

    /** Providers with a registered Phase-1 adapter. */
    public function isImplemented(): bool
    {
        return in_array($this, [
            self::GoogleAdSense,
            self::DirectBanner,
            self::HouseAds,
            self::CustomHtml,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::GoogleAdSense->value => 'Google AdSense',
            self::DirectBanner->value => 'Direct Banner',
            self::HouseAds->value => 'House Ads / Internal Promotion',
            self::CustomHtml->value => 'Custom HTML',
            self::Adsterra->value => 'Adsterra (future)',
            self::MediaNet->value => 'Media.net (future)',
            self::Ezoic->value => 'Ezoic (future)',
            self::PropellerAds->value => 'PropellerAds (future)',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value] ?? $this->value;
    }
}
