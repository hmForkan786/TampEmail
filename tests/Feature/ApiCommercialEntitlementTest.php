<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\BillingCycle;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'api-commercial-entitlement-secret']);
    seedCommercialCatalogue();
});

it('allows read access for entitled users and denies write without premium', function (): void {
    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $token = issueCommercialApiKey($user, ['mail_servers:read', 'mail_servers:write'], grantCommercial: false);

    $this->withToken($token)->getJson('/api/v1/mail-servers')->assertOk();
    $this->withToken($token)->postJson('/api/v1/mail-servers', mailServerPayload(['name' => 'Denied']))->assertForbidden()
        ->assertJsonPath('error.code', 'feature_not_available')
        ->assertJsonPath('error.details.feature', 'api.write');
});

it('denies read when api.read is disabled on the effective plan', function (): void {
    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $plan = Plan::query()->where('slug', 'free')->sole();
    $feature = Feature::query()->where('key', 'api.read')->sole();
    $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => false]]);
    $token = issueCommercialApiKey($user, ['mail_servers:read'], grantCommercial: false);

    $this->withToken($token)->getJson('/api/v1/mail-servers')->assertForbidden()
        ->assertJsonPath('error.details.feature', 'api.read')
        ->assertJsonPath('error.details.upgrade_required', true);
    expect(AuditLog::query()->where('action', 'commercial.api_read_denied')->exists())->toBeTrue();
});

it('allows read-only users to GET but not mutate outbound drafts', function (): void {
    ['user' => $user, 'token' => $token] = premiumWebhookFixture();
    $plan = Subscription::query()->where('user_id', $user->id)->firstOrFail()->plan;
    $feature = Feature::query()->where('key', 'api.write')->sole();
    $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => false]]);

    $this->withToken($token)->getJson('/api/v1/outbound-drafts')->assertOk();
    $this->withToken($token)->postJson('/api/v1/outbound-drafts', ['subject' => 'Denied'])->assertForbidden()
        ->assertJsonPath('error.details.feature', 'api.write');
});

it('fails closed for users without a mapped free plan feature', function (): void {
    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $plan = Plan::query()->where('slug', 'free')->sole();
    $feature = Feature::query()->where('key', 'api.read')->sole();
    $plan->features()->detach($feature->id);
    $token = issueCommercialApiKey($user, ['mail_servers:read'], grantCommercial: false);

    $this->withToken($token)->getJson('/api/v1/mail-servers')->assertForbidden();
});

it('falls back when premium subscription expires', function (): void {
    $user = User::factory()->create();
    grantApiWrite($user);
    $premium = Plan::query()->where('slug', 'premium')->sole();
    Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $premium->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subMinute(),
        'auto_renew' => false,
        'price' => '9.00',
        'currency' => 'USD',
    ]);
    $token = issueCommercialApiKey($user, ['outbound_messages:write'], grantCommercial: false);

    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload())->assertForbidden()
        ->assertJsonPath('error.details.feature', 'webhook.access');
});

it('does not grant premium access from an inactive premium plan', function (): void {
    $user = User::factory()->create();
    $premium = Plan::query()->where('slug', 'premium')->sole();
    $premium->forceFill(['is_active' => false])->save();
    Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $premium->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '9.00',
        'currency' => 'USD',
    ]);
    $token = issueCommercialApiKey($user, ['outbound_messages:write'], grantCommercial: false);

    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload())->assertForbidden();
});

it('applies the minimum of global and commercial rate limits with fail-closed zero', function (): void {
    ['user' => $user] = commercialPremiumUser();
    config(['abuse.rate_limits.api_per_minute' => 60]);
    setApiRateLimit($user, 2);
    $issued = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'rate',
        permissions: ['outbound_messages:read'],
        user: $user,
    );
    RateLimiter::clear('api-key:'.$issued->apiKey->id);
    $token = $issued->plainToken;

    $this->withToken($token)->getJson('/api/v1/outbound-drafts')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
    $this->withToken($token)->getJson('/api/v1/outbound-drafts')->assertOk();
    $this->withToken($token)->getJson('/api/v1/outbound-drafts')->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limit_exceeded')
        ->assertJsonPath('error.details.feature', 'api.max_requests_per_minute')
        ->assertHeader('Retry-After');
});

it('denies when the commercial rate limit resolves to zero', function (): void {
    ['user' => $user] = commercialPremiumUser();
    setApiRateLimit($user, 0);
    $token = issueCommercialApiKey($user, ['outbound_messages:read'], grantCommercial: false);

    $this->withToken($token)->getJson('/api/v1/outbound-drafts')->assertStatus(429)
        ->assertJsonPath('error.details.limit', 0);
});
