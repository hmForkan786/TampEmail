<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Storage;

final class AttachmentScanRetryableException extends \RuntimeException {}

final class AttachmentScanService
{
    private bool $terminalTransitionApplied = false;

    public function __construct(
        private readonly AttachmentScannerInterface $scanner,
        private readonly AuditLogWriter $audit,
    ) {}

    public function scan(Attachment $attachment): Attachment
    {
        $this->terminalTransitionApplied = false;

        if ($attachment->scan_status?->isTerminal()) {
            return $attachment;
        }

        if (config('attachments.scanner_backend', 'disabled') === 'disabled') {
            return $this->skipped($attachment, 'scanner_disabled');
        }

        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            return $this->failed($attachment, 'quarantine_missing');
        }

        if (! $this->claimForScanning($attachment)) {
            return $attachment->fresh() ?? $attachment;
        }

        $this->recordEvent($attachment, 'attachment.scan_started', AttachmentScanStatus::Scanning);

        try {
            $startedAt = microtime(true);
            $result = $this->scanner->scan(new AttachmentScanRequest(
                $attachment->storage_disk,
                $attachment->storage_path,
                $attachment->size_bytes,
                $attachment->checksum_sha256,
                $attachment->mime_type,
            ));
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $safeMetadata = array_filter([
                'scanner_backend' => config('attachments.scanner_backend', 'disabled'),
                'result_code' => $result->result->value,
                'scanner_version' => $result->scannerVersion,
                'signature' => $result->signature !== null
                    ? mb_substr(preg_replace('/[^A-Za-z0-9._:+ -]/', '', $result->signature) ?: 'unknown', 0, 120)
                    : null,
                'scan_duration_ms' => $durationMs,
            ]);

            if ($result->result === AttachmentScanResult::Failed && in_array($result->scannerVersion, ['clamav:unavailable', 'clamav:timeout', 'clamav:write'], true)) {
                Attachment::query()
                    ->whereKey($attachment->getKey())
                    ->where('scan_status', AttachmentScanStatus::Scanning)
                    ->update(['scan_status' => AttachmentScanStatus::Pending, 'is_safe' => null]);

                throw new AttachmentScanRetryableException('retryable_attachment_scan_failure');
            }

            return match ($result->result) {
                AttachmentScanResult::Clean => $this->clean($attachment, $safeMetadata, $durationMs),
                AttachmentScanResult::Infected => $this->infected($attachment, $result->signature, $safeMetadata, $durationMs),
                AttachmentScanResult::Failed => $this->failed($attachment, $this->sanitizeErrorCode($result->scannerVersion) ?? 'scanner_failed', $safeMetadata, $durationMs),
            };
        } catch (AttachmentScanRetryableException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return $this->failed($attachment, 'scanner_unavailable');
        }
    }

    public function terminalTransitionApplied(): bool
    {
        return $this->terminalTransitionApplied;
    }

    public function markSkipped(Attachment $attachment, string $reason = 'scanner_disabled'): Attachment
    {
        return $this->skipped($attachment, $reason);
    }

    private function claimForScanning(Attachment $attachment): bool
    {
        $claimed = Attachment::query()
            ->whereKey($attachment->getKey())
            ->where('scan_status', AttachmentScanStatus::Pending)
            ->update(['scan_status' => AttachmentScanStatus::Scanning, 'is_safe' => null]);

        if ($claimed === 1) {
            $attachment->scan_status = AttachmentScanStatus::Scanning;
            $attachment->is_safe = null;

            return true;
        }

        return false;
    }

    private function clean(Attachment $attachment, array $metadata, int $durationMs = 0): Attachment
    {
        $updated = $this->applyTerminal($attachment, AttachmentScanStatus::Clean, true, $metadata);
        if ($this->terminalTransitionApplied) {
            $this->recordEvent($attachment, 'attachment.scan_clean', AttachmentScanStatus::Clean, $durationMs);
        }

        return $updated;
    }

    private function infected(Attachment $attachment, ?string $signature, array $metadata = [], int $durationMs = 0): Attachment
    {
        $safeSignature = $signature !== null
            ? mb_substr(preg_replace('/[^A-Za-z0-9._:+ -]/', '', $signature) ?: 'unknown', 0, 120)
            : null;
        $updated = $this->applyTerminal(
            $attachment,
            AttachmentScanStatus::Infected,
            false,
            array_merge($metadata, ['malware_signature' => $safeSignature]),
        );
        if ($this->terminalTransitionApplied) {
            $this->recordEvent($attachment, 'attachment.scan_infected', AttachmentScanStatus::Infected, $durationMs, [
                'threat_label' => $safeSignature,
            ]);
        }

        return $updated;
    }

    private function failed(Attachment $attachment, string $reason, array $metadata = [], int $durationMs = 0): Attachment
    {
        $errorCode = $this->sanitizeErrorCode($reason) ?? 'scanner_failed';
        $updated = $this->applyTerminal(
            $attachment,
            AttachmentScanStatus::Failed,
            null,
            array_merge($metadata, ['scan_error' => $errorCode]),
        );
        if ($this->terminalTransitionApplied) {
            $this->recordEvent($attachment, 'attachment.scan_failed', AttachmentScanStatus::Failed, $durationMs, [
                'error_code' => $errorCode,
            ]);
        }

        return $updated;
    }

    private function skipped(Attachment $attachment, string $reason): Attachment
    {
        $errorCode = $this->sanitizeErrorCode($reason) ?? 'scanner_disabled';
        $updated = $this->applyTerminal(
            $attachment,
            AttachmentScanStatus::Skipped,
            null,
            ['scan_error' => $errorCode, 'scanner_backend' => 'disabled'],
        );
        if ($this->terminalTransitionApplied) {
            $this->recordEvent($attachment, 'attachment.scan_skipped', AttachmentScanStatus::Skipped, 0, [
                'error_code' => $errorCode,
            ]);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function applyTerminal(Attachment $attachment, AttachmentScanStatus $status, ?bool $isSafe, array $metadata): Attachment
    {
        $merged = array_merge(is_array($attachment->metadata) ? $attachment->metadata : [], $metadata);
        $updated = Attachment::query()
            ->whereKey($attachment->getKey())
            ->whereIn('scan_status', [AttachmentScanStatus::Pending, AttachmentScanStatus::Scanning])
            ->update([
                'scan_status' => $status,
                'is_safe' => $isSafe,
                'scanned_at' => now(),
                'metadata' => $merged,
            ]);
        $this->terminalTransitionApplied = $updated === 1;

        return $attachment->fresh() ?? $attachment;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordEvent(
        Attachment $attachment,
        string $action,
        AttachmentScanStatus $status,
        int $durationMs = 0,
        array $extra = [],
    ): void {
        $this->audit->write(
            $action,
            null,
            $attachment,
            null,
            null,
            array_filter([
                'attachment_id' => (string) $attachment->getKey(),
                'email_id' => (string) $attachment->email_id,
                'result_status' => $status->value,
                'scanner_backend' => (string) config('attachments.scanner_backend', 'disabled'),
                'duration_ms' => $durationMs > 0 ? $durationMs : null,
                'timestamp' => now()->toIso8601String(),
                ...$extra,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    private function sanitizeErrorCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._:-]/', '', $code) ?: null;

        return $sanitized !== null ? mb_substr($sanitized, 0, 80) : null;
    }
}
