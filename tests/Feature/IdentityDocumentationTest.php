<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('documents the identity architecture and operations suite', function (): void {
    $files = [
        'docs/IDENTITY_ARCHITECTURE.md',
        'docs/IDENTITY_SECURITY_POLICY.md',
        'docs/IDENTITY_OPERATIONS_RUNBOOK.md',
        'docs/IDENTITY_DEPLOYMENT_CHECKLIST.md',
        'docs/ACCOUNT_RECOVERY_POLICY.md',
    ];

    foreach ($files as $file) {
        expect(file_exists(base_path($file)))->toBeTrue($file.' missing');
        expect(filesize(base_path($file)))->toBeGreaterThan(200);
    }
});

it('exposes identity health command', function (): void {
    $this->artisan('identity:health')->assertSuccessful();
});
