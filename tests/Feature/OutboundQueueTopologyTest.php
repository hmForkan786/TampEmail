<?php

declare(strict_types=1);

use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\OutboundProviderEventType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Jobs\ProcessOutboundProviderEventJob;
use App\Services\Outbound\OutboundQueueReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'queue.workloads.outbound_delivery' => 'outbound-delivery',
        'queue.workloads.outbound_events' => 'outbound-events',
        'queue.workloads.outbound_maintenance' => 'outbound-maintenance',
        'queue.workloads.attachment_scanning' => 'attachment-scanning',
        'queue.default' => 'redis',
    ]);
});

it('assigns delivery jobs to the outbound-delivery queue', function (): void {
    $job = new DeliverOutboundMessageJob('msg-1');

    expect($job->queue)->toBe('outbound-delivery');
});

it('assigns provider event jobs to the outbound-events queue', function (): void {
    $job = new ProcessOutboundProviderEventJob(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-1',
        providerMessageId: '<msg@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    ));

    expect($job->queue)->toBe('outbound-events');
});

it('keeps outbound queues distinct from each other and from attachment scanning', function (): void {
    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['topology']['valid'])->toBeTrue()
        ->and($report['topology']['outbound_delivery'])->toBe('outbound-delivery')
        ->and($report['topology']['outbound_events'])->toBe('outbound-events')
        ->and($report['topology']['outbound_maintenance'])->toBe('outbound-maintenance');
});

it('fails closed when outbound queue names collide with each other', function (): void {
    config(['queue.workloads.outbound_events' => 'outbound-delivery']);

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['topology']['valid'])->toBeFalse()
        ->and($report['status'])->toBe('failed')
        ->and($report['issues'])->toContain('invalid_queue_topology');
});

it('fails closed when an outbound queue collides with attachment scanning', function (): void {
    config(['queue.workloads.outbound_maintenance' => 'attachment-scanning']);

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['topology']['valid'])->toBeFalse()
        ->and($report['status'])->toBe('failed')
        ->and($report['issues'])->toContain('invalid_queue_topology');
});

it('fails closed when an outbound queue name is blank', function (): void {
    config(['queue.workloads.outbound_maintenance' => '']);

    $report = app(OutboundQueueReadinessService::class)->report();

    expect($report['topology']['valid'])->toBeFalse()
        ->and($report['issues'])->toContain('invalid_queue_topology');
});
