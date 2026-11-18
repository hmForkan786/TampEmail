<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function adsDoc(string $relativePath): string
{
    $path = base_path($relativePath);
    expect(File::exists($path))->toBeTrue();

    return File::get($path);
}

it('documents provider-neutral ads architecture', function (): void {
    $architecture = adsDoc('docs/ADS_ARCHITECTURE.md');

    expect($architecture)->toContain('Prompt 661')
        ->toContain('Ad Decision Engine')
        ->toContain('AdProvider')
        ->toContain('google_adsense')
        ->toContain('house_ads')
        ->toContain('ads.visible')
        ->toContain('Internal promotion engine')
        ->toContain('/api/v1/ad/{placement}');
});

it('documents ads operations and deployment contracts', function (): void {
    $runbook = adsDoc('docs/ADS_OPERATIONS_RUNBOOK.md');
    $deploy = adsDoc('docs/ADS_DEPLOYMENT_CHECKLIST.md');
    $index = adsDoc('docs/README.md');

    expect($runbook)->toContain('ads:health')
        ->toContain('ads:expire-campaigns')
        ->toContain('emergency stop');

    expect($deploy)->toContain('ADS_ENABLED')
        ->toContain('AdPlacementSeeder')
        ->toContain('ADS_PREMIUM_HIDE');

    expect($index)->toContain('ADS_ARCHITECTURE.md')
        ->toContain('ADS_OPERATIONS_RUNBOOK.md')
        ->toContain('ADS_DEPLOYMENT_CHECKLIST.md');
});
