<?php

use App\Services\Inbound\InboundRetentionService;

it('is dry-run and fail-closed while inbound hold support is unavailable', function (): void {
    config(['inbound_retention.cleanup_enabled'=>false, 'inbound_retention.legal_hold_required'=>true]);
    $report = app(InboundRetentionService::class)->cleanup(false, false, 10);
    expect($report['deleted'])->toBe(0)->and($report['blocked'])->toBeTrue();
});

it('does not permit confirmed destructive cleanup without an inbound hold provider', function (): void {
    config(['inbound_retention.cleanup_enabled'=>false, 'inbound_retention.legal_hold_required'=>true]);
    $report = app(InboundRetentionService::class)->cleanup(false, true, 10);
    expect($report['deleted'])->toBe(0)->and($report['blocked'])->toBeTrue();
});
