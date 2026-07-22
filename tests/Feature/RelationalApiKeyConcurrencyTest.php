<?php

use App\Enums\BillingCycle;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Tests\Support\RelationalConcurrencyHarness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relational-db', 'concurrency');

/**
 * API-key quota concurrency tests require independent database sessions.
 * SQLite in-memory and a same-process pre-lock assertion are deliberately not
 * accepted as proof of CreateApiKeyAction's production locking behavior.
 */
function requireRelationalApiKeyConcurrencyHarness(): void
{
    $driver = config('database.default');

    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        test()->markTestSkipped(
            "SKIPPED: API-key concurrency tests require MySQL or PostgreSQL; current driver is {$driver}."
        );
    }

    if (env('RUN_RELATIONAL_TESTS', env('RUN_RELATIONAL_CONCURRENCY_TESTS')) !== '1') {
        test()->markTestSkipped(
            'SKIPPED: set RUN_RELATIONAL_TESTS=1 and provide an independent-process transaction harness.'
        );
    }
}

it('requires a relational database and explicit concurrency harness', function (): void {
    requireRelationalApiKeyConcurrencyHarness();
})->note('No SQLite or manual pre-lock concurrency proof is used.');

it('exercises the real issue path at a max_api_keys boundary', function (): void {
    requireRelationalApiKeyConcurrencyHarness();

    config(['api.key_hash_secret' => 'relational-test-only-secret']);
    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $plan = Plan::create([
        'slug' => 'relational-api-key-'.bin2hex(random_bytes(5)), 'name' => 'Relational API key quota',
        'price_monthly' => '0.00', 'price_yearly' => '0.00', 'currency' => 'USD',
        'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $feature = Feature::create([
        'key' => 'max_api_keys', 'name' => 'Max API keys', 'value_type' => ValueType::Json,
        'is_active' => true, 'display_order' => 1,
    ]);
    $plan->features()->attach($feature->id, ['feature_value' => ['limit' => 1]]);
    Subscription::create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(),
        'auto_renew' => true, 'price' => '0.00', 'currency' => 'USD',
    ]);

    $summary = RelationalConcurrencyHarness::run('api-key-quota', [
        'a' => ['user_id' => $user->id, 'permissions' => ['mail_servers:read']],
        'b' => ['user_id' => $user->id, 'permissions' => ['mail_servers:read']],
    ]);

    expect($summary['successes'])->toBe(1)
        ->and($summary['rejections'])->toBe(1)
        ->and($summary['errors'])->toBe(0)
        ->and($user->apiKeys()->whereNull('revoked_at')->count())->toBe(1)
        ->and(json_encode($summary))->not->toContain('token')
        ->and(json_encode($summary))->not->toContain('hash');
})->note('Required scenario: two issue() calls, max_api_keys=1, one success and one quota exception.');
