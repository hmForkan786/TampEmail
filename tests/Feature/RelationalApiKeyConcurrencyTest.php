<?php

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

    if (env('RUN_RELATIONAL_CONCURRENCY_TESTS') !== '1') {
        test()->markTestSkipped(
            'SKIPPED: set RUN_RELATIONAL_CONCURRENCY_TESTS=1 and provide an independent-process transaction harness.'
        );
    }
}

it('requires a relational database and explicit concurrency harness', function (): void {
    requireRelationalApiKeyConcurrencyHarness();
})->note('No SQLite or manual pre-lock concurrency proof is used.');

it('exercises the real issue path at a max_api_keys boundary', function (): void {
    requireRelationalApiKeyConcurrencyHarness();

    test()->markTestSkipped(
        'BLOCKED: this repository has no independent-process runner configured; refusing a false-positive concurrency assertion.'
    );
})->note('Required scenario: two issue() calls, max_api_keys=1, one success and one quota exception.');

it('exercises the real execute path and quota-state semantics', function (): void {
    requireRelationalApiKeyConcurrencyHarness();

    test()->markTestSkipped(
        'BLOCKED: this repository has no independent-process runner configured; refusing a false-positive concurrency assertion.'
    );
})->note('Required scenario: execute(), rollback, two-user isolation, revoked exclusion, expired-key counting.');
