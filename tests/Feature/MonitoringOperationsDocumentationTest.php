<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function monitoringDoc(string $relativePath): string
{
    $path = base_path($relativePath);
    expect(File::exists($path))->toBeTrue();

    return File::get($path);
}

it('documents the monitoring operations audit contract', function (): void {
    $audit = monitoringDoc('docs/MONITORING_OPERATIONS_AUDIT.md');

    expect($audit)->toContain('Prompt 659')
        ->toContain('platform:check')
        ->toContain('processes:health')
        ->toContain('inbound:health')
        ->toContain('outbound:status')
        ->toContain('billing:stripe-health')
        ->toContain('attachments:scanner-health')
        ->toContain('mail-servers:pool-status')
        ->toContain('backup:restore-health')
        ->toContain('withoutOverlapping')
        ->toContain('Accepted limitations')
        ->toContain('No Prometheus')
        ->toContain('No Laravel Horizon');
});

it('documents exit codes capacity and incident quick paths', function (): void {
    $runbook = monitoringDoc('docs/OPERATIONS_MONITORING_RUNBOOK.md');

    expect($runbook)->toContain('Exit-code contract')
        ->toContain('processes:health')
        ->toContain('mail-servers:pool-status')
        ->toContain('OUTBOUND_EMERGENCY_STOP')
        ->toContain('STRIPE_MAINTENANCE_MODE')
        ->toContain('SSLCOMMERZ_MAINTENANCE_MODE')
        ->toContain('Database outage')
        ->toContain('Queue outage')
        ->toContain('Payment outage')
        ->toContain('Malware event')
        ->toContain('Capacity signals');
});

it('provides a production operations checklist for prompt 659', function (): void {
    $checklist = monitoringDoc('docs/PRODUCTION_OPERATIONS_CHECKLIST.md');

    expect($checklist)->toContain('config:cache')
        ->toContain('route:cache')
        ->toContain('event:cache')
        ->toContain('schedule:list')
        ->toContain('platform:check --json')
        ->toContain('processes:health --json')
        ->toContain('backup:restore-health')
        ->toContain('Fake payment gateway');
});

it('indexes monitoring docs from the master operations runbook', function (): void {
    $index = monitoringDoc('docs/OPERATIONS_RUNBOOK.md');

    expect($index)->toContain('MONITORING_OPERATIONS_AUDIT.md')
        ->toContain('OPERATIONS_MONITORING_RUNBOOK.md')
        ->toContain('PRODUCTION_OPERATIONS_CHECKLIST.md')
        ->toContain('platform:check --json');
});

it('keeps monitoring docs free of local paths and credential material', function (): void {
    $content = implode("\n", [
        monitoringDoc('docs/MONITORING_OPERATIONS_AUDIT.md'),
        monitoringDoc('docs/OPERATIONS_MONITORING_RUNBOOK.md'),
        monitoringDoc('docs/PRODUCTION_OPERATIONS_CHECKLIST.md'),
    ]);

    expect($content)->not->toContain('C:\\xampp')
        ->and($content)->not->toMatch('/password\s*=/i')
        ->and($content)->not->toMatch('/(?:redis|mysql|pgsql):\/\/[^\s`]+/i')
        ->and($content)->not->toMatch('/-----BEGIN [A-Z ]+ PRIVATE KEY-----/')
        ->and($content)->not->toMatch('/\bsk_(live|test)_[A-Za-z0-9]+/');
});
