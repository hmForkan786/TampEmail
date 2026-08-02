<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function analyticsDoc(string $relativePath): string
{
    $path = base_path($relativePath);
    expect(File::exists($path))->toBeTrue();

    return File::get($path);
}

it('documents provider-neutral analytics architecture', function (): void {
    $architecture = analyticsDoc('docs/ANALYTICS_ARCHITECTURE.md');

    expect($architecture)->toContain('Prompt 663')
        ->toContain('Analytics Event Collector')
        ->toContain('read model')
        ->toContain('Scheduled aggregation')
        ->toContain('PII')
        ->toContain('analytics:rollup')
        ->toContain('AnalyticsDashboardService');
});

it('documents analytics operations and deployment contracts', function (): void {
    $runbook = analyticsDoc('docs/ANALYTICS_OPERATIONS_RUNBOOK.md');
    $deploy = analyticsDoc('docs/ANALYTICS_DEPLOYMENT_CHECKLIST.md');
    $index = analyticsDoc('docs/README.md');

    expect($runbook)->toContain('analytics:health')
        ->toContain('analytics:rollup')
        ->toContain('CSV');

    expect($deploy)->toContain('ANALYTICS_ENABLED')
        ->toContain('analytics:rollup --backfill')
        ->toContain('ANALYTICS_SCHEDULER_ROLLUP');

    expect($index)->toContain('ANALYTICS_ARCHITECTURE.md')
        ->and($index)->toContain('ANALYTICS_OPERATIONS_RUNBOOK.md')
        ->and($index)->toContain('ANALYTICS_DEPLOYMENT_CHECKLIST.md');
});
