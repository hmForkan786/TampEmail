<?php
declare(strict_types=1);

namespace App\Services\Inbound;

use App\Models\Attachment;
use App\Models\Email;
use App\Models\EmailBody;
use App\Models\EmailEvent;
use App\Models\EmailProcessingLog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class InboundRetentionService
{
    public function cleanup(bool $dryRun, bool $confirm, int $batchSize): array
    {
        $report = ['eligible'=>0,'deleted'=>0,'held'=>0,'skipped'=>0,'missing_files'=>0,'failed'=>0,'duration'=>0.0,'blocked'=>false,'blocked_reason'=>null];
        $started = microtime(true);
        if (! config('inbound_retention.cleanup_enabled', false) || ! config('inbound_retention.inbound_hold_supported', false)) {
            $report['blocked'] = true; $report['blocked_reason'] = ! config('inbound_retention.cleanup_enabled', false) ? 'disabled' : 'inbound_hold_support_unavailable';
            $report['duration'] = microtime(true) - $started;
            return $report;
        }
        if (! $confirm || $dryRun || $batchSize < 1) {
            $report['eligible'] = $this->eligibleCount();
            $report['duration'] = microtime(true) - $started;
            return $report;
        }
        if ($batchSize > 1000) throw new \InvalidArgumentException('Inbound retention batch size is bounded to 1000.');
        $report = $this->deleteEmails($report, $batchSize);
        $report['duration'] = microtime(true) - $started;
        return $report;
    }
    private function deleteEmails(array $report, int $batchSize): array
    {
        $cutoff = now()->subDays((int) config('inbound_retention.email_days'));
        while (true) {
            $emails = Email::query()->where('created_at','<',$cutoff)->whereDoesntHave('inboundHolds', fn ($q) => $q->active())->limit($batchSize)->get();
            if ($emails->isEmpty()) break;
            try { DB::transaction(function () use ($emails, &$report): void {
                foreach ($emails as $email) {
                    foreach ($email->attachments()->get() as $attachment) {
                        if (in_array($attachment->scan_status->value, ['pending','scanning'], true)) { $report['skipped']++; continue; }
                        if ($attachment->inboundHolds()->active()->exists()) { $report['held']++; continue; }
                        if (! $this->safeQuarantinePath($attachment->storage_path)) throw new \RuntimeException('Unsafe attachment storage path.');
                        $disk = Storage::disk($attachment->storage_disk);
                        if ($disk->exists($attachment->storage_path)) { if (! $disk->delete($attachment->storage_path)) throw new \RuntimeException('Attachment storage deletion failed.'); } else $report['missing_files']++;
                        $attachment->delete(); $report['deleted']++;
                    }
                    if ($email->attachments()->exists()) continue;
                    $email->body()->delete(); $email->events()->delete(); $email->processingLogs()->delete(); $email->delete(); $report['deleted']++;
                }
            }); } catch (\Throwable $e) { $report['failed']++; report($e); break; }
        }
        return $report;
    }
    private function safeQuarantinePath(string $path): bool { return $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\\\/]/', $path) && str_starts_with($path, 'quarantine/'); }

    private function eligibleCount(): int
    {
        $cutoff = now()->subDays((int) config('inbound_retention.email_days'));
        return Email::query()->where('created_at','<',$cutoff)->whereDoesntHave('inboundHolds', fn ($q) => $q->active())->count()
            + EmailBody::query()->where('created_at','<',$cutoff)->whereDoesntHave('email.inboundHolds', fn ($q) => $q->active())->count()
            + EmailEvent::query()->where('created_at','<',$cutoff)->whereDoesntHave('email.inboundHolds', fn ($q) => $q->active())->count()
            + EmailProcessingLog::query()->where('created_at','<',$cutoff)->whereDoesntHave('email.inboundHolds', fn ($q) => $q->active())->count()
            + Attachment::query()->where('created_at','<',$cutoff)->whereNotIn('scan_status',['pending','scanning'])
                ->whereDoesntHave('inboundHolds', fn ($q) => $q->active())->whereDoesntHave('email.inboundHolds', fn ($q) => $q->active())->count();
    }
}
