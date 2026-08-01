<?php

declare(strict_types=1);

use App\Enums\AffiliateCommissionPlanStatus;
use App\Enums\AffiliateCommissionType;
use App\Enums\AffiliateProfileStatus;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommissionPlan;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\Affiliates\AffiliateAttributionService;
use App\Services\Affiliates\AffiliateRegistrationService;
use Database\Seeders\AffiliateCommissionPlanSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function enableAffiliates(array $overrides = []): void
{
    config(array_merge([
        'affiliates.enabled' => true,
        'affiliates.registration_mode' => 'manual_approval',
        'affiliates.attribution_model' => 'last_click',
        'affiliates.commission_hold_days' => 14,
        'affiliates.min_withdrawal_minor' => 5000,
        'affiliates.default_currency' => 'USD',
        'affiliates.supported_currencies' => ['USD'],
        'affiliates.commission_base' => 'subtotal_after_discount',
        'affiliates.eligible_order_types.initial_purchase' => true,
        'affiliates.eligible_order_types.renewal' => false,
        'affiliates.eligible_order_types.recovery' => false,
        'affiliates.eligible_order_types.upgrade' => true,
        'affiliates.hash_key' => 'test-affiliate-hash-key',
        'affiliates.cookie.name' => 'temail_aff',
        'affiliates.cookie.days' => 30,
        'affiliates.redirect.default_path' => '/',
        'affiliates.redirect.allowed_paths' => ['/'],
        'affiliates.fraud.fast_conversion_seconds' => 0,
        'affiliates.fraud.excessive_clicks_per_hour' => 100000,
    ], $overrides));
}

function seedAffiliatePlan(): AffiliateCommissionPlan
{
    app(AffiliateCommissionPlanSeeder::class)->run();

    return AffiliateCommissionPlan::query()
        ->where('status', AffiliateCommissionPlanStatus::Active->value)
        ->orderBy('created_at')
        ->firstOrFail();
}

/**
 * @return array{0: User, 1: AffiliateProfile, 2: AffiliateCommissionPlan, 3: User}
 */
function makeAffiliateContext(array $planOverrides = [], array $configOverrides = []): array
{
    enableAffiliates($configOverrides);
    $plan = seedAffiliatePlan();

    if ($planOverrides !== []) {
        $plan->forceFill($planOverrides)->save();
        $plan = $plan->fresh();
    }

    $affiliateUser = User::factory()->create(['email' => 'affiliate-'.Str::lower(Str::random(8)).'@example.test']);
    $buyer = User::factory()->create(['email' => 'buyer-'.Str::lower(Str::random(8)).'@example.test']);

    config(['affiliates.registration_mode' => 'automatic']);
    $profile = app(AffiliateRegistrationService::class)->apply($affiliateUser, [
        'promotion_channel' => 'blog',
        'website_url' => 'https://example.test/blog',
        'audience_description' => 'Developers',
        'expected_traffic' => '1k/mo',
    ]);
    config(['affiliates.registration_mode' => 'manual_approval']);

    if ($profile->commission_plan_id !== $plan->getKey()) {
        $profile->forceFill(['commission_plan_id' => $plan->getKey()])->save();
    }

    $profile = $profile->fresh(['plan', 'user']);

    return [$affiliateUser, $profile, $plan, $buyer];
}

function makeActiveAffiliate(User $user, ?AffiliateCommissionPlan $plan = null): AffiliateProfile
{
    $previousMode = config('affiliates.registration_mode');
    config(['affiliates.enabled' => true, 'affiliates.registration_mode' => 'automatic']);
    $plan ??= seedAffiliatePlan();

    $profile = app(AffiliateRegistrationService::class)->apply($user, [
        'promotion_channel' => 'direct',
    ]);

    if ($profile->status !== AffiliateProfileStatus::Active) {
        $profile->forceFill([
            'status' => AffiliateProfileStatus::Active,
            'approved_at' => now(),
            'commission_plan_id' => $plan->getKey(),
        ])->save();
    }

    config(['affiliates.registration_mode' => $previousMode]);

    return $profile->fresh(['plan', 'user']);
}

/**
 * @return array{attribution: AffiliateAttribution, visitor_token: string}
 */
function recordReferralClick(AffiliateProfile $profile, array $query = []): array
{
    $request = Request::create('/?ref='.$profile->affiliate_code, 'GET', $query);
    $request->headers->set('User-Agent', 'AffiliateTestAgent/1.0');
    $request->server->set('REMOTE_ADDR', '203.0.113.10');

    $result = app(AffiliateAttributionService::class)->recordClick(
        $profile->affiliate_code,
        $request,
        null,
    );

    expect($result['attribution'])->toBeInstanceOf(AffiliateAttribution::class)
        ->and($result['visitor_token'])->toBeString()->not->toBeEmpty();

    return [
        'attribution' => $result['attribution'],
        'visitor_token' => $result['visitor_token'],
    ];
}

function linkBuyerAttribution(AffiliateProfile $profile, User $buyer): AffiliateAttribution
{
    $click = recordReferralClick($profile);
    $linked = app(AffiliateAttributionService::class)->linkUser($buyer, $click['visitor_token']);

    expect($linked)->toBeInstanceOf(AffiliateAttribution::class);

    return $linked->fresh();
}

function makePercentagePlan(array $overrides = []): AffiliateCommissionPlan
{
    return AffiliateCommissionPlan::query()->create(array_merge([
        'name' => 'Test Percentage',
        'status' => AffiliateCommissionPlanStatus::Active,
        'commission_type' => AffiliateCommissionType::Percentage,
        'percentage_bps' => 1000,
        'currency' => 'USD',
        'minimum_order_minor' => 0,
        'maximum_commission_minor' => null,
        'cookie_window_days' => 30,
        'commission_hold_days' => 14,
        'new_customer_only' => true,
        'recurring_commission_enabled' => false,
    ], $overrides));
}
