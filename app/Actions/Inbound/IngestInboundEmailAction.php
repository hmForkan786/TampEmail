<?php
declare(strict_types=1);
namespace App\Actions\Inbound;
use App\DTOs\Inbound\InboundResolution;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\Enums\EmailEventType;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Enums\ProcessingStatus;
use App\Models\Email;
use App\Models\EmailBody;
use App\Models\EmailEvent;
use App\Models\EmailProcessingLog;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Services\Inbound\InboundHtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ScanInboundAttachmentJob;
final class IngestInboundEmailAction
{
    public function __construct(private readonly InboundHtmlSanitizer $sanitizer) {}
    public function execute(ParsedInboundEmail $parsed, InboundResolution $resolution): Email
    {
        $storedPaths = [];
        try { return DB::transaction(function () use ($parsed, $resolution, &$storedPaths): Email {
            $existing = Email::query()->where('message_id', $parsed->messageId)->first(); if ($existing !== null) return $existing;
            if ($resolution->inboxId === null) throw new \InvalidArgumentException('Recipient was not resolved.');
            $maxCount = (int) config('attachments.max_count', 20); $maxBytes = (int) config('attachments.max_bytes', 26214400); $maxTotal = (int) config('attachments.max_total_bytes', 52428800);
            if (count($parsed->attachments) > $maxCount || array_sum(array_map(fn ($a): int => $a->sizeBytes, $parsed->attachments)) > $maxTotal || collect($parsed->attachments)->contains(fn ($a): bool => $a->sizeBytes > $maxBytes)) throw new \InvalidArgumentException('Attachment limits exceeded.');
            $email = Email::query()->create(['inbox_id'=>$resolution->inboxId,'message_id'=>$parsed->messageId,'sender_email'=>$parsed->senderEmail,'recipient_email'=>$parsed->recipientEmail,'subject'=>$parsed->subject,'received_at'=>$parsed->receivedAt,'has_html'=>$parsed->htmlBody !== null,'has_text'=>$parsed->textBody !== null,'has_attachments'=>$parsed->attachments !== [],'attachment_count'=>count($parsed->attachments),'size_bytes'=>$parsed->sizeBytes,'processing_status'=>ProcessingStatus::Stored,'headers'=>$parsed->headers]);
            EmailBody::query()->create(['email_id'=>$email->id,'html_body'=>$this->sanitizer->sanitize($parsed->htmlBody),'text_body'=>$parsed->textBody,'body_hash'=>$parsed->textBody !== null ? hash('sha256',$parsed->textBody) : null,'storage_driver'=>'database']);
            foreach ($parsed->attachments as $attachment) {
                $path = 'quarantine/'.$email->id.'/'.bin2hex(random_bytes(16));
                Storage::disk('attachments')->put($path, $attachment->content); $storedPaths[] = $path;
                $record = Attachment::query()->create(['email_id'=>$email->id,'original_filename'=>mb_substr(basename($attachment->filename),0,255),'stored_filename'=>basename($path),'mime_type'=>$attachment->mimeType,'extension'=>pathinfo($attachment->filename, PATHINFO_EXTENSION) ?: null,'size_bytes'=>$attachment->sizeBytes,'checksum_sha256'=>$attachment->checksumSha256,'storage_disk'=>'attachments','storage_path'=>$path,'is_safe'=>null,'scan_status'=>AttachmentScanStatus::Pending,'metadata'=>['inline'=>$attachment->inline,'content_id'=>$attachment->contentId]]);
                if (config('attachments.scanner_backend', 'disabled') !== 'disabled' && Storage::disk('attachments')->exists($path)) ScanInboundAttachmentJob::dispatch((string) $record->id)->afterCommit();
            }
            EmailEvent::query()->create(['email_id'=>$email->id,'event_type'=>EmailEventType::Received,'event_source'=>'ingestion','occurred_at'=>now(),'payload'=>['provider_message_id'=>$parsed->messageId]]);
            EmailProcessingLog::query()->create(['email_id'=>$email->id,'stage'=>ProcessingStage::StoreBody,'status'=>ProcessingLogStatus::Success,'worker'=>'inbound','duration_ms'=>0]); return $email;
        }); } catch (\Throwable $e) { foreach ($storedPaths as $path) Storage::disk('attachments')->delete($path); throw $e; }
    }
}
