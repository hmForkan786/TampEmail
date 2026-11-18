<?php

use App\Enums\BillingCycle;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\RelationalConcurrencyHarness;

uses(DatabaseTruncation::class)->group('relational-db', 'concurrency');

/**
 * Webhook endpoint quota concurrency tests require independent database sessions.
 * SQLite in-memory and a same-process pre-lock assertion are deliberately not
 * accepted as proof of WebhookEndpointService's production locking behavior.
 */
function requireRelationalWebhookConcurrencyHarness(): void
{
    $driver = config('database.default');

    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        test()->markTestSkipped(
            "SKIPPED: webhook endpoint concurrency tests require MySQL or PostgreSQL; current driver is {$driver}."
        );
    }

    if (env('RUN_RELATIONAL_TESTS', env('RUN_RELATIONAL_CONCURRENCY_TESTS')) !== '1') {
        test()->markTestSkipped(
            'SKIPPED: set RUN_RELATIONAL_TESTS=1 and provide an independent-process transaction harness.'
        );
    }
}

it('requires a relational database and explicit concurrency harness for webhooks', function (): void {
    requireRelationalWebhookConcurrencyHarness();

    expect(config('database.default'))->toBeIn(['mysql', 'pgsql'])
        ->and(env('RUN_RELATIONAL_TESTS'))->toBe('1');
})->note('No SQLite or manual pre-lock concurrency proof is used.');

it('exercises the real create path at a webhook.max_endpoints boundary', function (): void {
    requireRelationalWebhookConcurrencyHarness();

    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $plan = Plan::create([
        'slug' => 'relational-webhook-'.bin2hex(random_bytes(5)),
        'name' => 'Relational webhook quota',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $accessFeature = Feature::create([
        'key' => 'webhook.access',
        'name' => 'Webhook access',
        'value_type' => ValueType::Json,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $limitFeature = Feature::create([
        'key' => 'webhook.max_endpoints',
        'name' => 'Max webhook endpoints',
        'value_type' => ValueType::Json,
        'is_active' => true,
        'display_order' => 2,
    ]);
    $plan->features()->attach($accessFeature->id, ['feature_value' => ['enabled' => true]]);
    $plan->features()->attach($limitFeature->id, ['feature_value' => ['limit' => 1]]);
    Subscription::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $summary = RelationalConcurrencyHarness::run('webhook-endpoint-quota', [
        'a' => ['user_id' => $user->id, 'name' => 'Worker A', 'url' => 'https://example.com/webhooks/a'],
        'b' => ['user_id' => $user->id, 'name' => 'Worker B', 'url' => 'https://example.com/webhooks/b'],
    ]);

    expect($summary['successes'])->toBe(1)
        ->and($summary['rejections'])->toBe(1)
        ->and($summary['errors'])->toBe(0)
        ->and(WebhookEndpoint::query()->where('user_id', $user->id)->where('is_active', true)->count())->toBe(1)
        ->and(json_encode($summary))->not->toContain('secret');
})->note('Required scenario: two create() calls, webhook.max_endpoints=1, one success and one quota exception.');
