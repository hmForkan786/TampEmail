<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AttachmentScanStatus;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\Attachment;
use App\Models\EmailProcessingLog;
use App\Services\Audit\AuditLogWriter;
use App\Services\Inbound\AttachmentScanRetry;
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

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $attachmentId) {}

    public function uniqueId(): string
    {
        return $this->attachmentId;
    }

    public function tries(): int
    {
        return AttachmentScanRetry::maxAttempts();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return AttachmentScanRetry::backoffSeconds();
    }

    public function timeout(): int
    {
        return AttachmentScanRetry::jobTimeoutSeconds();
    }

    public function handle(AttachmentScanService $service): void
    {
        $attachment = Attachment::withTrashed()->find($this->attachmentId);
        if ($attachment === null || $attachment->trashed()) {
            return;
        }

        if ($attachment->scan_status?->isTerminal()) {
            return;
        }

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
        $attachment = Attachment::withTrashed()->find($this->attachmentId);
        if ($attachment === null || $attachment->trashed()) {
            return;
        }

        if ($attachment->scan_status?->isTerminal()) {
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

        $audit = app(AuditLogWriter::class);
        $payload = [
            'attachment_id' => (string) $attachment->id,
            'email_id' => (string) $attachment->email_id,
            'result_status' => AttachmentScanStatus::Failed->value,
            'scanner_backend' => (string) config('attachments.scanner_backend', 'disabled'),
            'error_code' => 'retry_exhausted',
            'attempt' => $this->attempts(),
            'max_attempts' => AttachmentScanRetry::maxAttempts(),
            'timestamp' => now()->toIso8601String(),
        ];
        $audit->write('attachment.scan_failed', null, $attachment, null, null, $payload);
        $audit->write('attachment.scan_retry_exhausted', null, $attachment, null, null, $payload);

        app(InboundFailureService::class)->record(
            $attachment->email_id,
            ProcessingStage::Scan,
            'attachment_scan_retry_exhausted',
            $this->attempts(),
            ['error_code' => 'retry_exhausted', 'attachment_id' => (string) $attachment->id],
        );
    }
}
