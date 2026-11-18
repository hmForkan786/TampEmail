<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\MailServer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Inbox\ExpireInboxesService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.key_hash_secret' => 'inbox-entitlement-test-secret']));

function commercialInboxFixture(string $plan = 'free'): array
{
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    if ($plan === 'premium') {
        Subscription::query()->create([
            'user_id' => $user->id, 'plan_id' => Plan::query()->where('slug', 'premium')->sole()->id,
            'status' => SubscriptionStatus::Active, 'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now()->subMinute(), 'auto_renew' => true, 'price' => '9.00', 'currency' => 'USD',
        ]);
    }
    $domain = Domain::query()->create(['domain' => 'commercial-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Commercial', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'retention_hours' => 24]);
    MailServer::query()->create(['name' => 'Commercial mail', 'hostname' => 'commercial.test', 'provider' => 'smtp', 'protocol' => 'smtp', 'is_active' => true, 'priority' => 1, 'last_health_check_at' => now(), 'pool_key' => 'standard']);
    grantApiWrite($user);
    $token = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'commercial-inbox', permissions: ['inboxes:write'], user: $user)->plainToken;

    return compact('user', 'domain', 'token');
}

function createCommercialInbox(string $token, Domain $domain, array $payload = []): TestResponse
{
    return test()->withToken($token)->postJson('/api/v1/inboxes', array_merge(['domain_id' => $domain->id], $payload));
}

it('allows Free and Premium users to create generated inboxes with their entitled retention', function (): void {
    foreach (['free' => 24, 'premium' => 720] as $plan => $hours) {
        ['domain' => $domain, 'token' => $token] = commercialInboxFixture($plan);
        $response = createCommercialInbox($token, $domain);

        $response->assertCreated();
        $inbox = Inbox::query()->findOrFail($response->json('data.id'));
        expect($inbox->local_part)->toStartWith('inbox-')
            ->and($inbox->expires_at?->diffInMinutes(now()->addHours($hours)))->toBeLessThanOrEqual(1);
    }
});

it('denies creation when the create entitlement is disabled', function (): void {
    ['user' => $user, 'domain' => $domain, 'token' => $token] = commercialInboxFixture();
    $plan = Plan::query()->where('slug', 'free')->sole();
    $feature = Feature::query()->where('key', 'inbox.create')->sole();
    $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => false]]);

    createCommercialInbox($token, $domain)->assertForbidden()->assertJsonPath('error.details.feature', 'inbox.create')->assertJsonPath('error.details.upgrade_required', true);
});

it('enforces active inbox limits, ignores deleted inboxes, and audits denials', function (): void {
    ['user' => $user, 'domain' => $domain, 'token' => $token] = commercialInboxFixture();
    foreach (range(1, 3) as $number) {
        createCommercialInbox($token, $domain, ['local_part' => 'free-'.$number])->assertForbidden();
    }
    // Free aliases are not entitled; create active rows directly to isolate quota behaviour.
    foreach (range(1, 3) as $number) {
        Inbox::query()->create(['domain_id' => $domain->id, 'user_id' => $user->id, 'local_part' => 'existing-'.$number, 'full_address' => 'existing-'.$number.'@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true, 'expires_at' => now()->addHour()]);
    }

    createCommercialInbox($token, $domain)->assertForbidden()
        ->assertJsonPath('error.details.feature', 'inbox.max_active')
        ->assertJsonPath('error.details.limit', 3)
        ->assertJsonPath('error.details.used', 3)
        ->assertJsonPath('error.details.remaining', 0);
    expect(AuditLog::query()->where('action', 'commercial.limit_reached')->where('user_id', $user->id)->exists())->toBeTrue();

    Inbox::query()->where('user_id', $user->id)->firstOrFail()->delete();
    createCommercialInbox($token, $domain)->assertCreated();
});

it('denies Free custom aliases and allows Premium aliases while rejecting reserved and duplicate values', function (): void {
    ['domain' => $freeDomain, 'token' => $freeToken] = commercialInboxFixture();
    createCommercialInbox($freeToken, $freeDomain, ['local_part' => 'wanted'])->assertForbidden()->assertJsonPath('error.details.feature', 'inbox.custom_alias');

    ['domain' => $premiumDomain, 'token' => $premiumToken] = commercialInboxFixture('premium');
    createCommercialInbox($premiumToken, $premiumDomain, ['local_part' => 'admin'])->assertUnprocessable();
    createCommercialInbox($premiumToken, $premiumDomain, ['local_part' => 'chosen'])->assertCreated();
    createCommercialInbox($premiumToken, $premiumDomain, ['local_part' => 'chosen'])->assertUnprocessable();
});

it('prunes inboxes at the entitlement-derived retention boundary', function (): void {
    ['domain' => $domain, 'token' => $token] = commercialInboxFixture();
    $response = createCommercialInbox($token, $domain);
    $inbox = Inbox::query()->findOrFail($response->json('data.id'));
    $inbox->forceFill(['expires_at' => now()])->save();

    expect(app(ExpireInboxesService::class)->process(true)['processed'])->toBe(1);
});
