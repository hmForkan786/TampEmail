<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AttachmentScanStatus;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\Attachment;
use App\Models\EmailProcessingLog;
use App\Services\Audit\AuditLogWriter;
use App\Services\Inbound\AttachmentScanService;
use App\Services\Inbound\InboundFailureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ScanInboundAttachmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->attachmentId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public readonly string $attachmentId) {}

    public function handle(AttachmentScanService $service): void
    {
        $attachment = Attachment::query()->findOrFail($this->attachmentId);
        $result = $service->scan($attachment);

        if ($service->terminalTransitionApplied()) {
            EmailProcessingLog::query()->create([
                'email_id' => $result->email_id,
                'stage' => ProcessingStage::Scan,
                'status' => $result->is_safe === true ? ProcessingLogStatus::Success : ProcessingLogStatus::Failed,
                'worker' => 'attachment-scanner',
                'duration_ms' => 0,
                'metadata' => ['scan_status' => $result->scan_status->value],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $attachment = Attachment::query()->find($this->attachmentId);
        if ($attachment === null) {
            return;
        }

        $metadata = array_merge(is_array($attachment->metadata) ? $attachment->metadata : [], [
            'scan_error' => 'retry_exhausted',
            'scanner_backend' => (string) config('attachments.scanner_backend', 'disabled'),
        ]);

        $updated = Attachment::query()
            ->whereKey($attachment->getKey())
            ->whereIn('scan_status', [AttachmentScanStatus::Pending, AttachmentScanStatus::Scanning])
            ->update([
                'scan_status' => AttachmentScanStatus::Failed,
                'is_safe' => null,
                'scanned_at' => now(),
                'metadata' => $metadata,
            ]);

        if ($updated !== 1) {
            return;
        }

        app(AuditLogWriter::class)->write(
            'attachment.scan_failed',
            null,
            $attachment,
            null,
            null,
            [
                'attachment_id' => (string) $attachment->id,
                'email_id' => (string) $attachment->email_id,
                'result_status' => AttachmentScanStatus::Failed->value,
                'scanner_backend' => (string) config('attachments.scanner_backend', 'disabled'),
                'error_code' => 'retry_exhausted',
                'timestamp' => now()->toIso8601String(),
            ],
        );

        app(InboundFailureService::class)->record(
            $attachment->email_id,
            ProcessingStage::Scan,
            'attachment_scan_retry_exhausted',
            $this->attempts(),
            ['error_code' => 'retry_exhausted', 'attachment_id' => (string) $attachment->id],
        );
    }
}
