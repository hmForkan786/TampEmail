<?php

declare(strict_types=1);

use App\Services\Ads\Providers\CustomHtmlAdProvider;
use App\Services\Ads\Providers\DirectBannerAdProvider;
use App\Services\Ads\Providers\GoogleAdSenseProvider;
use App\Services\Ads\Providers\HouseAdsProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Master enable switch
    |--------------------------------------------------------------------------
    |
    | When false, the decision engine returns no ad for every placement.
    | Per-plan entitlement (ads.visible) still applies when this is true.
    |
    */
    'enabled' => (bool) env('ADS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Premium hide (monetization only)
    |--------------------------------------------------------------------------
    |
    | When true, monetization campaigns are never shown when the viewer’s
    | effective plan has ads.visible disabled (Premium). Promotion campaigns
    | still follow their own targeting rules.
    |
    */
    'premium_hide' => (bool) env('ADS_PREMIUM_HIDE', true),

    /*
    |--------------------------------------------------------------------------
    | Default provider hint (ops only — never hardcoded in views)
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('ADS_PROVIDER', 'google_adsense'),

    /*
    |--------------------------------------------------------------------------
    | Emergency stop cache key
    |--------------------------------------------------------------------------
    */
    'emergency_stop_cache_key' => 'ads:emergency_stop',

    /*
    |--------------------------------------------------------------------------
    | Provider adapters (slug → implementation)
    |--------------------------------------------------------------------------
    |
    | Future providers (Adsterra, Media.net, Ezoic, PropellerAds) register here
    | without touching the decision engine or views.
    |
    */
    'providers' => [
        'google_adsense' => GoogleAdSenseProvider::class,
        'direct_banner' => DirectBannerAdProvider::class,
        'house_ads' => HouseAdsProvider::class,
        'custom_html' => CustomHtmlAdProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Google AdSense defaults (overridable per campaign)
    |--------------------------------------------------------------------------
    */
    'google_adsense' => [
        'publisher_id' => env('ADS_ADSENSE_PUBLISHER_ID'),
        'responsive' => (bool) env('ADS_ADSENSE_RESPONSIVE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics retention (days)
    |--------------------------------------------------------------------------
    */
    'statistics' => [
        'impression_retention_days' => (int) env('ADS_IMPRESSION_RETENTION_DAYS', 90),
        'click_retention_days' => (int) env('ADS_CLICK_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler toggles
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'expire_campaigns' => (bool) env('ADS_SCHEDULER_EXPIRE', true),
        'refresh_budgets' => (bool) env('ADS_SCHEDULER_BUDGETS', true),
        'prune_statistics' => (bool) env('ADS_SCHEDULER_PRUNE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safe HTML allow-list for custom_html / house_ads body
    |--------------------------------------------------------------------------
    */
    'html' => [
        'allowed_tags' => ['a', 'p', 'br', 'strong', 'em', 'span', 'div', 'img', 'ul', 'ol', 'li'],
        'allowed_attributes' => ['href', 'src', 'alt', 'title', 'class', 'rel', 'target'],
        'require_https_urls' => (bool) env('ADS_REQUIRE_HTTPS_URLS', true),
    ],

];
