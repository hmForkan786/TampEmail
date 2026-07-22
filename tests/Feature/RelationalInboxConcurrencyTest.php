<?php

use App\Enums\BillingCycle;
use App\Enums\InboxType;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\MailServer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Tests\Support\RelationalConcurrencyHarness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relational-db', 'concurrency');

/**
 * These tests intentionally do not simulate concurrency on SQLite.
 *
 * A real run requires MySQL/PostgreSQL plus RUN_RELATIONAL_TESTS=1
 * and an external process runner capable of opening independent connections.
 * Without that runner, a same-process test would be a false concurrency proof.
 */
function requireRelationalConcurrencyHarness(): void
{
    $driver = config('database.default');

    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        test()->markTestSkipped(
            "SKIPPED: relational concurrency tests require MySQL or PostgreSQL; current driver is {$driver}."
        );
    }

    if (env('RUN_RELATIONAL_TESTS', env('RUN_RELATIONAL_CONCURRENCY_TESTS')) !== '1') {
        test()->markTestSkipped(
            'SKIPPED: set RUN_RELATIONAL_TESTS=1 with an external process runner to execute true parallel transactions.'
        );
    }
}

it('requires a production relational database and explicit concurrency harness', function (): void {
    requireRelationalConcurrencyHarness();
})->note('No same-process or SQLite concurrency simulation is accepted.');

it('reserves the user quota boundary across independent transactions', function (): void {
    requireRelationalConcurrencyHarness();

    $user = User::factory()->create(['platform_role' => PlatformRole::Operator]);
    $plan = Plan::create([
        'slug' => 'relational-inbox-'.bin2hex(random_bytes(5)), 'name' => 'Relational inbox quota',
        'price_monthly' => '0.00', 'price_yearly' => '0.00', 'currency' => 'USD',
        'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $quota = Feature::firstOrCreate(
        ['key' => 'max_inboxes'],
        ['name' => 'Max inboxes', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 1],
    );
    $pools = Feature::firstOrCreate(
        ['key' => 'mail_server_pools'],
        ['name' => 'Mail server pools', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 2],
    );
    $plan->features()->attach($quota->id, ['feature_value' => ['limit' => 1]]);
    $plan->features()->attach($pools->id, ['feature_value' => ['pools' => ['standard']]]);
    Subscription::create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(),
        'auto_renew' => true, 'price' => '0.00', 'currency' => 'USD',
    ]);
    $domain = Domain::create([
        'domain' => 'relational-'.bin2hex(random_bytes(5)).'.example.test',
        'display_name' => 'Relational test domain', 'is_active' => true, 'is_public' => true,
        'allow_registration' => true, 'is_healthy' => true, 'priority' => 1,
        'max_mailboxes' => null, 'retention_hours' => 24, 'metadata' => null,
    ]);
    $server = MailServer::create([
        'name' => 'Relational test server', 'hostname' => 'relational.example.test',
        'provider' => 'smtp', 'protocol' => 'smtp', 'is_active' => true, 'priority' => 1,
        'last_health_check_at' => now(), 'pool_key' => 'standard', 'max_inboxes' => 10,
    ]);

    $summary = RelationalConcurrencyHarness::run('inbox-user-quota', [
        'a' => ['user_id' => $user->id, 'domain_id' => $domain->id, 'local_part' => 'worker-a-'.bin2hex(random_bytes(3)), 'full_address' => 'worker-a-'.bin2hex(random_bytes(3)).'@'.$domain->domain, 'inbox_type' => InboxType::Temporary->value, 'expires_at' => now()->addHour()->toIso8601String(), 'metadata' => null],
        'b' => ['user_id' => $user->id, 'domain_id' => $domain->id, 'local_part' => 'worker-b-'.bin2hex(random_bytes(3)), 'full_address' => 'worker-b-'.bin2hex(random_bytes(3)).'@'.$domain->domain, 'inbox_type' => InboxType::Temporary->value, 'expires_at' => now()->addHour()->toIso8601String(), 'metadata' => null],
    ]);

    expect($summary['successes'])->toBe(1)
        ->and($summary['rejections'])->toBe(1)
        ->and($summary['errors'])->toBe(0)
        ->and($user->inboxes()->count())->toBe(1)
        ->and($user->inboxes()->first()->mail_server_id)->not->toBeNull()
        ->and($server->inboxes()->count())->toBeLessThanOrEqual(10)
        ->and(json_encode($summary))->not->toContain('token')
        ->and(json_encode($summary))->not->toContain('hash');
})->note('Required scenario: same-user quota boundary, rollback, and two-user isolation.');

it('reserves the MailServer capacity boundary across independent transactions', function (): void {
    requireRelationalConcurrencyHarness();

    $users = [
        User::factory()->create(['platform_role' => PlatformRole::Operator]),
        User::factory()->create(['platform_role' => PlatformRole::Operator]),
    ];
    $plan = Plan::create([
        'slug' => 'relational-capacity-'.bin2hex(random_bytes(5)), 'name' => 'Relational capacity quota',
        'price_monthly' => '0.00', 'price_yearly' => '0.00', 'currency' => 'USD',
        'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $quota = Feature::firstOrCreate(['key' => 'max_inboxes'], ['name' => 'Max inboxes', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 1]);
    $pools = Feature::firstOrCreate(['key' => 'mail_server_pools'], ['name' => 'Mail server pools', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 2]);
    $plan->features()->attach($quota->id, ['feature_value' => ['limit' => null]]);
    $plan->features()->attach($pools->id, ['feature_value' => ['pools' => ['standard']]]);
    foreach ($users as $user) Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true, 'price' => '0.00', 'currency' => 'USD']);
    $domain = Domain::create(['domain' => 'capacity-'.bin2hex(random_bytes(5)).'.example.test', 'display_name' => 'Capacity test domain', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'priority' => 1, 'max_mailboxes' => null, 'retention_hours' => 24, 'metadata' => null]);
    $server = MailServer::create(['name' => 'Capacity test server', 'hostname' => 'capacity.example.test', 'provider' => 'smtp', 'protocol' => 'smtp', 'is_active' => true, 'priority' => 1, 'last_health_check_at' => now(), 'pool_key' => 'standard', 'max_inboxes' => 1]);
    $payload = static fn (User $user, string $label): array => ['user_id' => $user->id, 'domain_id' => $domain->id, 'local_part' => $label.'-'.bin2hex(random_bytes(3)), 'full_address' => $label.'-'.bin2hex(random_bytes(3)).'@'.$domain->domain, 'inbox_type' => InboxType::Temporary->value, 'expires_at' => now()->addHour()->toIso8601String(), 'metadata' => null];
    $summary = RelationalConcurrencyHarness::run('mail-server-capacity', ['a' => $payload($users[0], 'capacity-a'), 'b' => $payload($users[1], 'capacity-b')]);
    $server->refresh();
    expect($summary['successes'])->toBe(1)->and($summary['rejections'])->toBe(1)->and($summary['errors'])->toBe(0)->and($server->inboxes()->count())->toBe(1)->and($server->inboxes()->count())->toBeLessThanOrEqual($server->max_inboxes)->and($server->inboxes()->first()->mail_server_id)->toBe($server->id)->and(json_encode($summary))->not->toContain('token')->and(json_encode($summary))->not->toContain('hash');
})->note('Required scenario: same-server capacity, anonymous pool, and expired/deleted/inactive inboxes.');

it('reserves the anonymous public-pool capacity boundary across independent transactions', function (): void {
    requireRelationalConcurrencyHarness();
    config(['inbox.public_mail_server_pool' => 'public']);

    $domain = Domain::create(['domain' => 'public-'.bin2hex(random_bytes(5)).'.example.test', 'display_name' => 'Public test domain', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'priority' => 1, 'max_mailboxes' => null, 'retention_hours' => 24, 'metadata' => null]);
    $server = MailServer::create(['name' => 'Public capacity server', 'hostname' => 'public.example.test', 'provider' => 'smtp', 'protocol' => 'smtp', 'is_active' => true, 'priority' => 1, 'last_health_check_at' => now(), 'pool_key' => 'public', 'max_inboxes' => 1]);
    $payload = static fn (string $label): array => ['domain_id' => $domain->id, 'local_part' => $label.'-'.bin2hex(random_bytes(3)), 'full_address' => $label.'-'.bin2hex(random_bytes(3)).'@'.$domain->domain, 'inbox_type' => InboxType::Temporary->value, 'expires_at' => now()->addHour()->toIso8601String(), 'metadata' => null];
    $summary = RelationalConcurrencyHarness::run('anonymous-capacity', ['a' => $payload('anonymous-a'), 'b' => $payload('anonymous-b')]);
    $server->refresh();
    expect($summary['successes'])->toBe(1)->and($summary['rejections'])->toBe(1)->and($summary['errors'])->toBe(0)->and($server->pool_key)->toBe('public')->and($server->inboxes()->count())->toBe(1)->and($server->inboxes()->first()->mail_server_id)->toBe($server->id)->and(json_encode($summary))->not->toContain('token')->and(json_encode($summary))->not->toContain('hash');
})->note('Required scenario: anonymous public-pool capacity boundary with no user entitlement dependency.');
