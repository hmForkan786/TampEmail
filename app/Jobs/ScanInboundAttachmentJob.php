<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Models\Attachment;
use App\Services\Inbound\AttachmentScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
final class ScanInboundAttachmentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3; public int $timeout = 120; public int $uniqueFor = 3600;
    public function uniqueId(): string { return $this->attachmentId; }
    public function backoff(): array { return [60, 300, 900]; }
    public function __construct(public readonly string $attachmentId) {}
    public function handle(AttachmentScanService $service): void
    {
        $attachment = Attachment::query()->findOrFail($this->attachmentId); $result = $service->scan($attachment);
        \App\Models\EmailProcessingLog::query()->create(['email_id'=>$result->email_id,'stage'=>\App\Enums\ProcessingStage::Scan,'status'=>$result->is_safe === true ? \App\Enums\ProcessingLogStatus::Success : \App\Enums\ProcessingLogStatus::Failed,'worker'=>'attachment-scanner','duration_ms'=>0,'metadata'=>['scan_status'=>$result->scan_status->value]]);
    }
    public function failed(\Throwable $exception): void
    {
        $attachment = Attachment::query()->find($this->attachmentId); if ($attachment === null) return;
        $attachment->update(['scan_status'=>\App\Enums\AttachmentScanStatus::Failed,'is_safe'=>null,'scanned_at'=>now(),'metadata'=>['scan_error'=>'retry_exhausted']]);
        app(\App\Services\Inbound\InboundFailureService::class)->record($attachment->email_id, \App\Enums\ProcessingStage::Scan, 'attachment_scan_retry_exhausted', $this->attempts(), ['error_code'=>'retry_exhausted','attachment_id'=>(string)$attachment->id]);
    }
}
