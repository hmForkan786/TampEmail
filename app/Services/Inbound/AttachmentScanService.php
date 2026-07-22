<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

final class AttachmentScanService
{
    public function __construct(private readonly AttachmentScannerInterface $scanner) {}
    public function scan(Attachment $attachment): Attachment
    {
        if (in_array($attachment->scan_status, [AttachmentScanStatus::Clean, AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true)) return $attachment;
        if (config('attachments.scanner_backend', 'disabled') === 'disabled') return $attachment;
        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) return $this->failed($attachment, 'quarantine_missing');
        $attachment->update(['scan_status' => AttachmentScanStatus::Scanning, 'is_safe' => null]);
        try {
            $result = $this->scanner->scan(new AttachmentScanRequest($attachment->storage_disk, $attachment->storage_path, $attachment->size_bytes, $attachment->checksum_sha256, $attachment->mime_type));
            return match ($result->result) {
                AttachmentScanResult::Clean => $attachment->fresh()->forceFill(['scan_status'=>AttachmentScanStatus::Clean,'is_safe'=>true,'scanned_at'=>now(),'metadata'=>array_filter(['scanner_version'=>$result->scannerVersion])])->save() ? $attachment->fresh() : $attachment,
                AttachmentScanResult::Infected => $this->infected($attachment, $result->signature),
                AttachmentScanResult::Failed => $this->failed($attachment, 'scanner_failed'),
            };
        } catch (\Throwable) { return $this->failed($attachment, 'scanner_unavailable'); }
    }
    private function failed(Attachment $a, string $reason): Attachment { $a->update(['scan_status'=>AttachmentScanStatus::Failed,'is_safe'=>null,'scanned_at'=>now(),'metadata'=>['scan_error'=>$reason]]); return $a->fresh(); }
    private function infected(Attachment $a, ?string $signature): Attachment { $a->update(['scan_status'=>AttachmentScanStatus::Infected,'is_safe'=>false,'scanned_at'=>now(),'metadata'=>array_filter(['malware_signature'=>$signature])]); return $a->fresh(); }
}
