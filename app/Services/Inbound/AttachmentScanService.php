<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

final class AttachmentScanRetryableException extends \RuntimeException {}

final class AttachmentScanService
{
    private bool $terminalTransitionApplied = false;

    public function __construct(private readonly AttachmentScannerInterface $scanner) {}
    public function scan(Attachment $attachment): Attachment
    {
        $this->terminalTransitionApplied = false;
        if (in_array($attachment->scan_status, [AttachmentScanStatus::Clean, AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true)) return $attachment;
        if (config('attachments.scanner_backend', 'disabled') === 'disabled') return $attachment;
        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) return $this->failed($attachment, 'quarantine_missing');
        $attachment->update(['scan_status' => AttachmentScanStatus::Scanning, 'is_safe' => null]);
        try {
            $startedAt = microtime(true);
            $result = $this->scanner->scan(new AttachmentScanRequest($attachment->storage_disk, $attachment->storage_path, $attachment->size_bytes, $attachment->checksum_sha256, $attachment->mime_type));
            $safeMetadata = array_filter([
                'scanner_backend' => config('attachments.scanner_backend', 'disabled'),
                'result_code' => $result->result->value,
                'scanner_version' => $result->scannerVersion,
                'signature' => $result->signature !== null ? mb_substr(preg_replace('/[^A-Za-z0-9._:+ -]/', '', $result->signature) ?: 'unknown', 0, 120) : null,
                'scan_duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
            if ($result->result === AttachmentScanResult::Failed && in_array($result->scannerVersion, ['clamav:unavailable', 'clamav:timeout', 'clamav:write'], true)) {
                $attachment->update(['scan_status' => AttachmentScanStatus::Pending, 'is_safe' => null]);
                throw new AttachmentScanRetryableException('retryable_attachment_scan_failure');
            }
            return match ($result->result) {
                AttachmentScanResult::Clean => $this->clean($attachment, $safeMetadata),
                AttachmentScanResult::Infected => $this->infected($attachment, $result->signature, $safeMetadata),
                AttachmentScanResult::Failed => $this->failed($attachment, 'scanner_failed', $safeMetadata),
            };
        } catch (AttachmentScanRetryableException $exception) { throw $exception;
        } catch (\Throwable) { return $this->failed($attachment, 'scanner_unavailable'); }
    }
    public function terminalTransitionApplied(): bool { return $this->terminalTransitionApplied; }
    private function clean(Attachment $a, array $metadata): Attachment
    {
        $updated = Attachment::query()->whereKey($a->getKey())->whereIn('scan_status', [AttachmentScanStatus::Pending, AttachmentScanStatus::Scanning])->update(['scan_status'=>AttachmentScanStatus::Clean,'is_safe'=>true,'scanned_at'=>now(),'metadata'=>$metadata]);
        $this->terminalTransitionApplied = $updated === 1;
        return $a->fresh();
    }
    private function failed(Attachment $a, string $reason, array $metadata = []): Attachment
    {
        $updated = Attachment::query()->whereKey($a->getKey())->whereIn('scan_status', [AttachmentScanStatus::Pending, AttachmentScanStatus::Scanning])->update(['scan_status'=>AttachmentScanStatus::Failed,'is_safe'=>null,'scanned_at'=>now(),'metadata'=>array_merge($metadata, ['scan_error'=>$reason])]);
        $this->terminalTransitionApplied = $updated === 1;
        return $a->fresh();
    }
    private function infected(Attachment $a, ?string $signature, array $metadata = []): Attachment
    {
        $safeSignature = $signature !== null
            ? mb_substr(preg_replace('/[^A-Za-z0-9._:+ -]/', '', $signature) ?: 'unknown', 0, 120)
            : null;
        $updated = Attachment::query()->whereKey($a->getKey())->whereIn('scan_status', [AttachmentScanStatus::Pending, AttachmentScanStatus::Scanning])->update([
            'scan_status' => AttachmentScanStatus::Infected,
            'is_safe' => false,
            'scanned_at' => now(),
            'metadata' => array_merge($metadata, ['malware_signature' => $safeSignature]),
        ]);

        $this->terminalTransitionApplied = $updated === 1;
        return $a->fresh();
    }
}
