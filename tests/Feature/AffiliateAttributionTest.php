<?php

declare(strict_types=1);

use App\Enums\AffiliateAttributionStatus;
use App\Models\AffiliateAttribution;
use App\Models\User;
use App\Services\Affiliates\AffiliateAttributionService;
use App\Services\Affiliates\AffiliateReferralRedirectService;
use App\Services\Affiliates\AffiliateRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates();
    seedAffiliatePlan();
});

it('records a valid referral click with hashed identifiers', function (): void {
    [, $profile] = makeAffiliateContext();
    $click = recordReferralClick($profile, ['utm_source' => 'twitter']);

    $attr = $click['attribution']->fresh();
    expect($attr->status)->toBe(AffiliateAttributionStatus::Active)
        ->and($attr->ip_hash)->not->toBeNull()
        ->and($attr->user_agent_hash)->not->toBeNull()
        ->and($attr->ip_hash)->not->toContain('203.0.113')
        ->and($attr->utm_source)->toBe('twitter')
        ->and(strlen($click['visitor_token']))->toBe(64);
});

it('ignores invalid referral codes safely', function (): void {
    $request = Request::create('/?ref=!!!!', 'GET');
    $result = app(AffiliateAttributionService::class)->recordClick('!!!!', $request);

    expect($result['attribution'])->toBeNull()
        ->and($result['cookie_should_set'])->toBeFalse();
});

it('does not attribute clicks for suspended affiliates', function (): void {
    [, $profile] = makeAffiliateContext();
    $admin = User::factory()->platformAdmin()->create();
    app(AffiliateRegistrationService::class)->suspend($profile, $admin, 'test');

    $request = Request::create('/?ref='.$profile->affiliate_code, 'GET');
    $result = app(AffiliateAttributionService::class)->recordClick($profile->affiliate_code, $request);

    expect($result['attribution'])->toBeNull();
});

it('keeps first click under first_click policy', function (): void {
    [, $first] = makeAffiliateContext([], ['affiliates.attribution_model' => 'first_click']);
    $secondUser = User::factory()->create();
    $second = makeActiveAffiliate($secondUser);

    expect(config('affiliates.attribution_model'))->toBe('first_click');

    $click = recordReferralClick($first);
    $token = $click['visitor_token'];

    $request = Request::create('/?ref='.$second->affiliate_code, 'GET');
    $secondClick = app(AffiliateAttributionService::class)->recordClick($second->affiliate_code, $request, $token);

    expect($secondClick['attribution']->affiliate_profile_id)->toBe($first->getKey());
});

it('switches affiliate under last_click policy', function (): void {
    [, $first] = makeAffiliateContext([], ['affiliates.attribution_model' => 'last_click']);
    $secondUser = User::factory()->create();
    $second = makeActiveAffiliate($secondUser);

    $click = recordReferralClick($first);
    $token = $click['visitor_token'];

    $request = Request::create('/?ref='.$second->affiliate_code, 'GET');
    $secondClick = app(AffiliateAttributionService::class)->recordClick($second->affiliate_code, $request, $token);

    expect($secondClick['attribution']->affiliate_profile_id)->toBe($second->getKey())
        ->and($click['attribution']->fresh()->status)->toBe(AffiliateAttributionStatus::Invalidated);
});

it('rejects tampered cookie tokens safely', function (): void {
    expect(app(AffiliateAttributionService::class)->resolveVisitorTokenFromCookie('not-a-token'))->toBeNull()
        ->and(app(AffiliateAttributionService::class)->resolveVisitorTokenFromCookie('xyz'))->toBeNull();
});

it('blocks open redirects', function (): void {
    $service = app(AffiliateReferralRedirectService::class);

    expect($service->resolveDestination('https://evil.example/phish'))->toBe('/')
        ->and($service->resolveDestination('//evil.example'))->toBe('/')
        ->and($service->resolveDestination('/'))->toBe('/');
});

it('expires and prunes attributions', function (): void {
    [, $profile] = makeAffiliateContext();
    $click = recordReferralClick($profile);
    $attr = $click['attribution'];
    $attr->forceFill([
        'expires_at' => now()->subDay(),
        'status' => AffiliateAttributionStatus::Active,
    ])->save();

    $expired = app(AffiliateAttributionService::class)->expireDue(100);
    expect($expired)->toBe(1)
        ->and($attr->fresh()->status)->toBe(AffiliateAttributionStatus::Expired);

    Carbon::setTestNow(now()->addDays(120));
    $dry = app(AffiliateAttributionService::class)->pruneExpired(100, true);
    expect($dry)->toBeGreaterThanOrEqual(1);

    $pruned = app(AffiliateAttributionService::class)->pruneExpired(100, false);
    expect($pruned)->toBeGreaterThanOrEqual(1)
        ->and(AffiliateAttribution::query()->whereKey($attr->getKey())->exists())->toBeFalse();
    Carbon::setTestNow();
});

it('blocks self-referral attribution conversion', function (): void {
    [, $profile] = makeAffiliateContext();
    $click = recordReferralClick($profile);
    $linked = app(AffiliateAttributionService::class)->linkUser($profile->user, $click['visitor_token']);

    expect($linked)->toBeNull()
        ->and($click['attribution']->fresh()->status)->toBe(AffiliateAttributionStatus::Invalidated);
});
