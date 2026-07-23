<?php

use App\Enums\AttachmentScanStatus;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailProcessingLog;
use App\Models\Inbox;
use App\Services\Inbound\InboundMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function metricsEmail(): Email
{
    $domain = Domain::query()->create(['domain' => 'metrics-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Metrics', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'retention_hours' => 24]);
    $inbox = Inbox::query()->create(['domain_id' => $domain->id, 'local_part' => 'metrics', 'full_address' => 'metrics@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true]);
    return Email::query()->create(['inbox_id' => $inbox->id, 'message_id' => 'metrics-'.bin2hex(random_bytes(3)), 'sender_email' => 'sender@test', 'recipient_email' => $inbox->full_address, 'received_at' => now()->subMinute(), 'size_bytes' => 1, 'processing_status' => 'stored']);
}

it('aggregates stage counters, latency and attachment backlog without mutation', function (): void {
    $email = metricsEmail();
    EmailProcessingLog::query()->create(['email_id' => $email->id, 'stage' => ProcessingStage::Parse, 'status' => ProcessingLogStatus::Success, 'duration_ms' => 120, 'metadata' => ['metric_stage' => 'resolved', 'queue_delay_ms' => 40]]);
    EmailProcessingLog::query()->create(['email_id' => $email->id, 'stage' => ProcessingStage::Scan, 'status' => ProcessingLogStatus::Failed, 'duration_ms' => 80, 'metadata' => ['metric_stage' => 'failed', 'classification' => 'rejected', 'retryable' => false]]);
    $attachment = Attachment::query()->create(['email_id' => $email->id, 'original_filename' => 'x', 'stored_filename' => 'x', 'mime_type' => 'text/plain', 'size_bytes' => 1, 'checksum_sha256' => hash('sha256', 'x'), 'storage_disk' => 'attachments', 'storage_path' => 'metrics/x', 'scan_status' => AttachmentScanStatus::Pending, 'is_safe' => null]);
    $before = $email->fresh()->toArray();

    $report = app(InboundMetricsService::class)->report();
    expect($report)->toHaveKeys(['last_5_minutes', 'last_hour', 'last_24_hours'])
        ->and($report['last_5_minutes']['counters']['received'])->toBe(1)
        ->and($report['last_5_minutes']['counters']['resolved'])->toBe(1)
        ->and($report['last_5_minutes']['counters']['failed'])->toBe(1)
        ->and($report['last_5_minutes']['counters']['rejected'])->toBe(1)
        ->and($report['last_5_minutes']['counters']['attachment_pending'])->toBe(1)
        ->and($report['last_5_minutes']['latency_ms']['parse_duration_ms'])->toBe(120.0)
        ->and($report['last_5_minutes']['latency_ms']['scan_duration_ms'])->toBe(80.0)
        ->and($report['last_5_minutes']['backlog']['pending_scan'])->toBe(1)
        ->and($email->fresh()->toArray())->toBe($before)
        ->and($attachment->fresh()->scan_status)->toBe(AttachmentScanStatus::Pending);
});

it('reports replayed counters and threshold breaches with safe output', function (): void {
    config(['inbound_metrics.thresholds.failure_rate' => 0.01, 'inbound_metrics.thresholds.retry_exhaustion' => 1]);
    $email = metricsEmail();
    EmailProcessingLog::query()->create(['email_id' => $email->id, 'stage' => ProcessingStage::Parse, 'status' => ProcessingLogStatus::Failed, 'duration_ms' => 1, 'metadata' => ['failure_code' => 'retry_exhausted', 'attempts' => 3, 'token' => 'secret-not-metric']]);
    AuditLog::query()->create(['action' => 'inbound.failure_replayed', 'auditable_type' => Email::class, 'auditable_id' => $email->id, 'metadata' => ['source' => 'admin']]);

    $health = app(InboundMetricsService::class)->health();
    expect($health['status'])->toBe('degraded')->and($health['breaches'])->toContain('failure_rate')->and($health['windows']['last_5_minutes']['counters']['replayed'])->toBe(1)
        ->and(json_encode($health))->not->toContain('secret-not-metric');
});

it('keeps event counters distinct and discards high-cardinality identifiers', function (): void {
    $email = metricsEmail();
    $recorder = app(App\Services\Inbound\InboundMetricsRecorder::class);
    $recorder->record((string) $email->id, 'received', ProcessingStage::Receive, ProcessingLogStatus::Success, ['attachment_id' => 'attachment-secret-id', 'classification' => 'received']);
    $recorder->record((string) $email->id, 'failed', ProcessingStage::Parse, ProcessingLogStatus::Failed, ['attachment_id' => 'attachment-secret-id', 'classification' => 'failed']);
    AuditLog::query()->create(['action' => 'inbound.failure_replayed', 'auditable_type' => Email::class, 'auditable_id' => $email->id, 'metadata' => ['source' => 'admin']]);

    $report = app(InboundMetricsService::class)->report();
    expect($report['last_5_minutes']['counters']['received'])->toBe(1)
        ->and($report['last_5_minutes']['counters']['failed'])->toBe(1)
        ->and($report['last_5_minutes']['counters']['replayed'])->toBe(1)
        ->and(json_encode($email->processingLogs()->latest()->first()->metadata))->not->toContain('attachment-secret-id')
        ->and(json_encode($report))->not->toContain('attachment-secret-id');
});

it('returns safe healthy empty output and exposes the health command', function (): void {
    $health = app(InboundMetricsService::class)->health();
    expect($health['status'])->toBe('healthy')
        ->and($health['windows']['last_5_minutes']['failure_rate'])->toBe(0.0)
        ->and($health)->not->toHaveKey('scanner')
        ->and(json_encode($health))->not->toContain('scanner');
    expect(Artisan::call('inbound:health'))->toBe(0);
    expect(Artisan::output())->toContain('healthy')->not->toContain('password')->not->toContain('scanner');
});

it('returns degraded and failed command exit codes with safe JSON output', function (): void {
    $this->mock(InboundMetricsService::class, function ($mock): void {
        $mock->shouldReceive('health')->once()->andReturn(['status' => 'degraded', 'breaches' => ['failure_rate']]);
    });
    expect(Artisan::call('inbound:health'))->toBe(1)
        ->and(Artisan::output())->toContain('degraded')->not->toContain('password');

    $this->mock(InboundMetricsService::class, function ($mock): void {
        $mock->shouldReceive('health')->once()->andThrow(new RuntimeException('database password=secret'));
    });
    expect(Artisan::call('inbound:health'))->toBe(1)
        ->and(Artisan::output())->toContain('failed')->not->toContain('password')->not->toContain('secret');
});
