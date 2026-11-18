<?php

use App\Models\Feature;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Services\Commercial\CommercialQuotaResolver;
use App\Services\Commercial\CommercialUsageSummaryService;
use App\Services\Webhook\WebhookEndpointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.key_hash_secret' => 'commercial-summary-secret']));

it('calculates remaining quota fail-closed for finite inventory features', function (): void {
    ['user' => $user] = commercialPremiumUser();
    setApiKeyLimit($user, 2);
    setWebhookEndpointLimit($user, 3);

    $snapshot = app(CommercialQuotaResolver::class)->snapshot($user, 'max_api_keys', 'inventory');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['limit'])->toBe(2)
        ->and($snapshot['used'])->toBe(0)
        ->and($snapshot['remaining'])->toBe(2)
        ->and($snapshot['unlimited'])->toBeFalse();
});

it('treats malformed limits as zero remaining capacity', function (): void {
    ['user' => $user, 'plan' => $plan] = commercialPremiumUser();
    $feature = Feature::query()->where('key', 'max_api_keys')->sole();
    $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['limit' => 'not-a-number']]);

    expect(app(CommercialQuotaResolver::class)->resolveLimit($user, 'max_api_keys'))->toBe(0);
});

it('returns a unified owner usage summary from the API', function (): void {
    ['user' => $user, 'token' => $token] = premiumWebhookFixture();
    setWebhookEndpointLimit($user, 5);

    $response = $this->withToken($token)->getJson('/api/v1/commercial/usage')->assertOk()
        ->assertJsonPath('data.plan', 'premium')
        ->assertJsonPath('data.features.max_api_keys.limit', 10);

    expect($response->json('data.features')['webhook.max_endpoints']['limit'])->toBe(5);

    $response->assertJsonStructure([
        'data' => [
            'plan',
            'subscription_status',
            'upgrade_required',
            'recommended_plan',
            'features' => [
                'outbound_messages_per_period' => ['limit', 'used', 'remaining', 'unlimited'],
                'max_api_keys' => ['limit', 'used', 'remaining', 'unlimited'],
                'webhook.max_endpoints' => ['limit', 'used', 'remaining', 'unlimited'],
            ],
        ],
    ]);
});

it('includes period meter usage from subscription_usage rows', function (): void {
    ['user' => $user] = commercialPremiumUser();
    $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();
    $feature = Feature::query()->where('key', 'outbound_messages_per_period')->sole();

    SubscriptionUsage::query()->create([
        'subscription_id' => $subscription->id,
        'feature_id' => $feature->id,
        'used_value' => 25,
        'limit_value' => 1000,
        'reset_period' => 'monthly',
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $summary = app(CommercialUsageSummaryService::class)->forUser($user, evaluateThresholds: false);

    expect($summary['features']['outbound_messages_per_period']['used'])->toBe(25)
        ->and($summary['features']['outbound_messages_per_period']['remaining'])->toBe(975);
});

it('does not recommend upgrade when premium quota is exhausted', function (): void {
    ['user' => $user] = commercialPremiumUser();
    setWebhookEndpointLimit($user, 1);
    app(WebhookEndpointService::class)->create($user, webhookPayload());

    $summary = app(CommercialUsageSummaryService::class)->forUser($user, evaluateThresholds: false);

    expect($summary['features']['webhook.max_endpoints']['remaining'])->toBe(0)
        ->and($summary['upgrade_required'])->toBeFalse()
        ->and($summary['recommended_plan'])->toBeNull();
});
