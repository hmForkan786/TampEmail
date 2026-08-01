<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/affiliate_helpers.php';

it('runs affiliates:health successfully', function (): void {
    enableAffiliates();
    seedAffiliatePlan();

    $exit = Artisan::call('affiliates:health');

    expect($exit)->toBeIn([0, 1])
        ->and(Artisan::output())->toContain('healthy');
});

it('lists affiliate artisan commands', function (): void {
    $exit = Artisan::call('list');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('affiliates:mature-commissions')
        ->and($output)->toContain('affiliates:prune-attributions')
        ->and($output)->toContain('affiliates:expire-attributions')
        ->and($output)->toContain('affiliates:health');
});
