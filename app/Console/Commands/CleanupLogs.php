<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LogRetentionService;
use Illuminate\Console\Command;

final class CleanupLogs extends Command
{
    protected $signature = 'logs:cleanup {--dry-run : Report eligible rows without deleting} {--confirm : Allow API request-log deletion} {--confirm-audit-delete : Allow audit deletion when hold support exists} {--batch= : Maximum rows per transaction}';
    protected $description = 'Perform bounded, policy-controlled log retention cleanup.';

    public function handle(LogRetentionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('confirm');
        $batch = (int) ($this->option('batch') ?: config('retention.batch_size', 500));
        $started = microtime(true);
        try {
            $api = $service->cleanupApiRequestLogs((int) config('retention.api_request_logs_days'), $batch, $dryRun);
            $audit = $service->cleanupAuditLogs((int) config('retention.audit_logs_days'), $batch, $dryRun, (bool) $this->option('confirm-audit-delete'));
        } catch (\Throwable $e) { $this->error('Cleanup failed: '.$e->getMessage()); return self::FAILURE; }
        $this->line('API request logs eligible: '.$api['eligible']);
        $this->line('API request logs deleted: '.$api['deleted']);
        $this->line('Audit logs eligible: '.$audit['eligible']);
        $this->line('Audit logs deleted: '.$audit['deleted']);
        $this->line('Audit logs held: '.$audit['audit_logs_held']);
        $this->line('Skipped due to hold: '.($audit['skipped_due_to_hold'] ? 'yes' : 'no'));
        $this->line('Failed batches: '.($api['failed_batches'] + $audit['failed_batches']));
        $this->line('Duration: '.number_format(microtime(true) - $started, 3).'s');
        $this->line('api_logs_eligible='.$api['eligible'].' api_logs_deleted='.$api['deleted'].' audit_logs_eligible='.$audit['eligible'].' audit_logs_deleted='.$audit['deleted'].' audit_logs_held='.$audit['audit_logs_held'].' skipped_due_to_hold='.($audit['skipped_due_to_hold'] ? '1' : '0').' failed_batches='.($api['failed_batches'] + $audit['failed_batches']));
        return self::SUCCESS;
    }
}
