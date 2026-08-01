<?php

declare(strict_types=1);

use App\Enums\AffiliateCommissionEntryStatus;
use App\Models\AffiliateCommissionEntry;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Affiliates\AffiliateConversionService;
use App\Services\Affiliates\AffiliateWithdrawalService;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';
require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates();
    config(['api.key_hash_secret' => 'affiliate-api-test-secret']);
});

function createAffiliateApiToken(User $owner): string
{
    $credentials = app(ApiKeyTokenGenerator::class)->generate();
    ApiKey::query()->create([
        'user_id' => $owner->id,
        'name' => 'affiliate-test-'.uniqid(),
        'key_prefix' => $credentials['key_prefix'],
        'key_hash' => $credentials['key_hash'],
        'permissions' => [],
        'rate_limit_per_minute' => 60,
    ]);

    return $credentials['plain_token'];
}

it('returns owner affiliate dashboard via api', function (): void {
    [$affiliateUser, $profile] = makeAffiliateContext();
    $token = createAffiliateApiToken($affiliateUser);

    $this->withToken($token)
        ->getJson('/api/v1/affiliate/dashboard')
        ->assertOk()
        ->assertJsonPath('data.affiliate_code', $profile->affiliate_code);
});

it('denies foreign affiliate withdrawal access', function (): void {
    [$affiliateUser, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 0, 'percentage_bps' => 10000]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'auth-wd'));
    $order->forceFill(['subtotal_minor' => 20000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 20000])->save();
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'auth-wd-evt'));
    app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    $entry = AffiliateCommissionEntry::query()->where('affiliate_profile_id', $profile->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute(), 'status' => AffiliateCommissionEntryStatus::Available])->save();

    $profile->forceFill(['payout_method' => 'paypal', 'payout_details_encrypted' => 'a@b.c'])->save();
    $withdrawal = app(AffiliateWithdrawalService::class)->request($profile, 5000, 'USD', 'paypal', 'a@b.c', 'auth-1');

    $other = User::factory()->create();
    makeActiveAffiliate($other);
    $otherToken = createAffiliateApiToken($other);

    $this->withToken($otherToken)
        ->postJson('/api/v1/affiliate/withdrawals/'.$withdrawal->getKey().'/cancel')
        ->assertNotFound();
});

it('allows applying for affiliate program via api', function (): void {
    seedAffiliatePlan();
    $user = User::factory()->create();
    $token = createAffiliateApiToken($user);

    $this->withToken($token)
        ->postJson('/api/v1/affiliate/apply', [
            'promotion_channel' => 'newsletter',
            'audience_description' => 'Builders',
        ])
        ->assertCreated();
});
