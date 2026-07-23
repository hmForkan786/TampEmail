<?php

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Services\Inbound\AttachmentScanRetryableException;
use App\Services\Inbound\AttachmentScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function scanJobAttachment(string $status = 'pending'): Attachment
{
    $domain = Domain::query()->create(['domain' => 'job-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Job', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'retention_hours' => 24]);
    $inbox = Inbox::query()->create(['domain_id' => $domain->id, 'local_part' => 'job', 'full_address' => 'job@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true]);
    $email = Email::query()->create(['inbox_id' => $inbox->id, 'message_id' => 'job-'.bin2hex(random_bytes(3)), 'sender_email' => 'sender@test', 'recipient_email' => $inbox->full_address, 'received_at' => now(), 'size_bytes' => 1, 'processing_status' => 'received']);
    return Attachment::query()->create(['email_id' => $email->id, 'original_filename' => 'x', 'stored_filename' => 'x', 'mime_type' => 'text/plain', 'size_bytes' => 1, 'checksum_sha256' => hash('sha256', 'x'), 'storage_disk' => 'attachments', 'storage_path' => 'job/x', 'scan_status' => $status, 'is_safe' => $status === 'clean']);
}

it('does not call the scanner for terminal attachments', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    foreach (['clean', 'infected', 'failed'] as $status) {
        $attachment = scanJobAttachment($status);
        $scanner = Mockery::mock(AttachmentScannerInterface::class);
        $scanner->shouldNotReceive('scan');
        app()->instance(AttachmentScannerInterface::class, $scanner);
        app(AttachmentScanService::class)->scan($attachment);
    }
});

it('leaves unavailable scanner outcomes pending for queue retry', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = scanJobAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'x');
    app()->instance(AttachmentScannerInterface::class, new class implements AttachmentScannerInterface {
        public function scan(AttachmentScanRequest $request): AttachmentScanResultData { return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:unavailable'); }
    });
    expect(fn () => app(AttachmentScanService::class)->scan($attachment))->toThrow(AttachmentScanRetryableException::class);
    expect($attachment->fresh()->scan_status)->toBe(AttachmentScanStatus::Pending);
});

it('persists one terminal event for a successful job transition', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = scanJobAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'x');
    app()->instance(AttachmentScannerInterface::class, new class implements AttachmentScannerInterface {
        public function scan(AttachmentScanRequest $request): AttachmentScanResultData { return new AttachmentScanResultData(AttachmentScanResult::Infected, 'test-signature'); }
    });
    $job = new ScanInboundAttachmentJob((string) $attachment->id);
    $job->handle(app(AttachmentScanService::class), app(App\Services\Inbound\InboundMetricsRecorder::class));
    expect($attachment->fresh()->scan_status)->toBe(AttachmentScanStatus::Infected)->and($attachment->fresh()->is_safe)->toBeFalse()->and(App\Models\EmailProcessingLog::query()->where('worker', 'attachment-scanner')->count())->toBe(1);
    app(AttachmentScanService::class)->scan($attachment->fresh());
    expect(App\Models\EmailProcessingLog::query()->where('worker', 'attachment-scanner')->count())->toBe(1);
});
