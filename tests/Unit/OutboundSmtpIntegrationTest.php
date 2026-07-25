<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * Optional live SMTP submission. Disabled unless RUN_OUTBOUND_SMTP_TESTS=1 and
 * approved sandbox credentials are configured. Never uses real user data.
 */
it('optionally submits through a sandbox SMTP transport when explicitly enabled', function (): void {
    if (! filter_var(env('RUN_OUTBOUND_SMTP_TESTS', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('External SMTP sandbox credentials are not configured; set RUN_OUTBOUND_SMTP_TESTS=1 to enable.');
    }

    expect(config('outbound.smtp.host'))->not->toBeEmpty()
        ->and(config('outbound.smtp.username'))->not->toBeEmpty()
        ->and(env('OUTBOUND_SMTP_TEST_RECIPIENT'))->not->toBeEmpty();
})->group('smtp-integration');
