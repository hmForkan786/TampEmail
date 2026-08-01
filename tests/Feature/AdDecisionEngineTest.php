<?php

declare(strict_types=1);

use App\Enums\AdAudience;
use App\Enums\AdCampaignPurpose;
use App\Enums\AdCampaignStatus;
use App\Enums\AdPromotionKind;
use App\Enums\AdProviderName;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Services\Ads\AdDecisionEngine;
use App\Services\Ads\AdEmergencyStopService;
use Database\Seeders\AdPlacementSeeder;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CommercialPlanFeatureSeeder::class);
    $this->seed(AdPlacementSeeder::class);
    Cache::forget((string) config('ads.emergency_stop_cache_key'));
    config([
        'ads.enabled' => true,
        'ads.premium_hide' => true,
        'ads.google_adsense.publisher_id' => 'ca-pub-1234567890123456',
    ]);
});

function makeHouseCampaign(string $placementKey = 'dashboard', array $overrides = []): AdCampaign
{
    $placement = AdPlacement::query()->where('key', $placementKey)->firstOrFail();

    $campaign = AdCampaign::query()->create(array_merge([
        'name' => 'Upgrade banner',
        'provider' => AdProviderName::HouseAds->value,
        'purpose' => AdCampaignPurpose::Promotion->value,
        'promotion_kind' => AdPromotionKind::Upgrade->value,
        'status' => AdCampaignStatus::Active->value,
        'priority' => 10,
        'targeting' => ['audience' => AdAudience::FreeOnly->value],
        'provider_config' => [
            'headline' => 'Go Premium',
            'body' => '<p>Remove ads and unlock outbound mail.</p>',
            'cta_label' => 'Upgrade',
            'cta_url' => '/pricing',
        ],
    ], $overrides));

    $campaign->placements()->sync([$placement->getKey()]);

    return $campaign->fresh(['placements']);
}

function makeMonetizationCampaign(string $placementKey = 'footer', array $overrides = []): AdCampaign
{
    $placement = AdPlacement::query()->where('key', $placementKey)->firstOrFail();

    $campaign = AdCampaign::query()->create(array_merge([
        'name' => 'Footer AdSense',
        'provider' => AdProviderName::GoogleAdSense->value,
        'purpose' => AdCampaignPurpose::Monetization->value,
        'status' => AdCampaignStatus::Active->value,
        'priority' => 50,
        'targeting' => ['audience' => AdAudience::PremiumExcluded->value],
        'provider_config' => [
            'publisher_id' => 'ca-pub-1234567890123456',
            'slot_id' => '1234567890',
            'responsive' => true,
        ],
    ], $overrides));

    $campaign->placements()->sync([$placement->getKey()]);

    return $campaign->fresh(['placements']);
}

it('returns no ad when emergency stop is engaged', function (): void {
    makeHouseCampaign();
    app(AdEmergencyStopService::class)->engage();

    $decision = app(AdDecisionEngine::class)->decide('dashboard', recordImpression: false);

    expect($decision->show)->toBeFalse()
        ->and($decision->reason)->toBe('emergency_stop');
});

it('hides monetization ads for premium users via commercial entitlement', function (): void {
    makeMonetizationCampaign();
    ['user' => $premium] = commercialPremiumUser();

    $decision = app(AdDecisionEngine::class)->decide('footer', $premium, recordImpression: false);

    expect($decision->show)->toBeFalse()
        ->and($decision->reason)->toBe('no_eligible_campaign');
});

it('shows monetization ads for free users', function (): void {
    makeMonetizationCampaign();
    $user = \App\Models\User::factory()->create();
    ensureFreeCommercialUser($user);

    $decision = app(AdDecisionEngine::class)->decide('footer', $user, recordImpression: true);

    expect($decision->show)->toBeTrue()
        ->and($decision->provider)->toBe('google_adsense')
        ->and($decision->purpose)->toBe('monetization')
        ->and($decision->render?->data['publisher_id'] ?? null)->toBe('ca-pub-1234567890123456')
        ->and($decision->impressionId)->not->toBeNull();
});

it('shows internal promotion upgrade banner to free users only', function (): void {
    makeHouseCampaign();
    $free = \App\Models\User::factory()->create();
    ensureFreeCommercialUser($free);
    ['user' => $premium] = commercialPremiumUser();

    $freeDecision = app(AdDecisionEngine::class)->decide('dashboard', $free, recordImpression: false);
    $premiumDecision = app(AdDecisionEngine::class)->decide('dashboard', $premium, recordImpression: false);

    expect($freeDecision->show)->toBeTrue()
        ->and($freeDecision->provider)->toBe('house_ads')
        ->and($freeDecision->render?->data['promotion_kind'] ?? null)->toBe('upgrade')
        ->and($premiumDecision->show)->toBeFalse();
});

it('allows maintenance promotions for premium when purpose is promotion', function (): void {
    makeHouseCampaign('dashboard', [
        'name' => 'Maintenance',
        'promotion_kind' => AdPromotionKind::Maintenance->value,
        'targeting' => ['audience' => AdAudience::All->value],
        'provider_config' => [
            'headline' => 'Scheduled maintenance',
            'body' => 'Brief downtime tonight.',
            'cta_label' => 'Status',
            'cta_url' => '/status',
        ],
    ]);
    ['user' => $premium] = commercialPremiumUser();

    $decision = app(AdDecisionEngine::class)->decide('dashboard', $premium, recordImpression: false);

    expect($decision->show)->toBeTrue()
        ->and($decision->purpose)->toBe('promotion');
});

it('selects highest priority eligible campaign for a placement', function (): void {
    makeHouseCampaign('sidebar', [
        'name' => 'Low priority',
        'priority' => 100,
        'targeting' => ['audience' => AdAudience::All->value],
    ]);
    makeHouseCampaign('sidebar', [
        'name' => 'High priority',
        'priority' => 1,
        'targeting' => ['audience' => AdAudience::All->value],
        'provider_config' => [
            'headline' => 'Priority win',
            'body' => 'Selected',
            'cta_label' => 'Go',
            'cta_url' => '/go',
        ],
    ]);

    $decision = app(AdDecisionEngine::class)->decide('sidebar', recordImpression: false);

    expect($decision->show)->toBeTrue()
        ->and($decision->render?->data['headline'] ?? null)->toBe('Priority win');
});

it('rejects unsafe custom html and javascript urls', function (): void {
    $placement = AdPlacement::query()->where('key', 'blog')->firstOrFail();
    $campaign = AdCampaign::query()->create([
        'name' => 'Bad HTML',
        'provider' => AdProviderName::CustomHtml->value,
        'purpose' => AdCampaignPurpose::Monetization->value,
        'status' => AdCampaignStatus::Active->value,
        'priority' => 10,
        'targeting' => ['audience' => AdAudience::All->value],
        'provider_config' => [
            'html' => '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>',
        ],
    ]);
    $campaign->placements()->sync([$placement->getKey()]);

    $decision = app(AdDecisionEngine::class)->decide('blog', recordImpression: false);

    // Sanitized markup may still render if strip_tags leaves an <a> without javascript —
    // assert no script/onerror remain when shown, or empty decision if fully stripped.
    if ($decision->show) {
        $markup = (string) ($decision->render?->data['markup'] ?? '');
        expect($markup)->not->toContain('<script')
            ->and($markup)->not->toContain('onerror')
            ->and($markup)->not->toContain('javascript:');
    } else {
        expect($decision->reason)->toBeIn(['render_failed', 'no_eligible_campaign', 'unsafe_render']);
    }
});

it('exposes decision via public ad API without api key', function (): void {
    makeMonetizationCampaign();

    $response = $this->getJson('/api/v1/ad/footer?track=0');

    $response->assertOk()
        ->assertJsonPath('data.show', true)
        ->assertJsonPath('data.provider', 'google_adsense');
});

it('reports healthy ads subsystem after placements are seeded', function (): void {
    $this->artisan('ads:health')
        ->assertSuccessful();
});

it('expires due campaigns via scheduler command', function (): void {
    Event::fake([\App\Events\Ads\CampaignExpired::class]);
    makeHouseCampaign('login', [
        'ends_at' => now()->subHour(),
        'targeting' => ['audience' => AdAudience::All->value],
    ]);

    $this->artisan('ads:expire-campaigns')->assertSuccessful();

    expect(AdCampaign::query()->where('status', AdCampaignStatus::Expired->value)->count())->toBe(1);
    Event::assertDispatched(\App\Events\Ads\CampaignExpired::class);
});
