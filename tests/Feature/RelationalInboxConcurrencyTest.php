<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relational-db', 'concurrency');

/**
 * These tests intentionally do not simulate concurrency on SQLite.
 *
 * A real run requires MySQL/PostgreSQL plus RUN_RELATIONAL_CONCURRENCY_TESTS=1
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

    if (env('RUN_RELATIONAL_CONCURRENCY_TESTS') !== '1') {
        test()->markTestSkipped(
            'SKIPPED: set RUN_RELATIONAL_CONCURRENCY_TESTS=1 with an external process runner to execute true parallel transactions.'
        );
    }
}

it('requires a production relational database and explicit concurrency harness', function (): void {
    requireRelationalConcurrencyHarness();
})->note('No same-process or SQLite concurrency simulation is accepted.');

it('reserves the user quota boundary across independent transactions', function (): void {
    requireRelationalConcurrencyHarness();

    test()->markTestSkipped(
        'BLOCKED: an external worker/process harness is required to coordinate independent transactions; no false-positive assertion is used.'
    );
})->note('Required scenario: same-user quota boundary, rollback, and two-user isolation.');

it('reserves the MailServer capacity boundary across independent transactions', function (): void {
    requireRelationalConcurrencyHarness();

    test()->markTestSkipped(
        'BLOCKED: an external worker/process harness is required to coordinate independent transactions; no false-positive assertion is used.'
    );
})->note('Required scenario: same-server capacity, anonymous pool, and expired/deleted/inactive inboxes.');
