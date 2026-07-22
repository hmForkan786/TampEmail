<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LogRetentionService
{
    /** @return array{eligible:int,deleted:int,failed_batches:int} */
    public function cleanupApiRequestLogs(int $days, int $batchSize, bool $dryRun): array
    {
        $this->validate($days, (int) config('retention.api_request_logs.minimum_days'), (int) config('retention.api_request_logs.maximum_days'), $batchSize);
        $cutoff = now()->subDays($days);
        $eligible = ApiRequestLog::query()->where('created_at', '<', $cutoff)->count();
        if ($dryRun) return ['eligible' => $eligible, 'deleted' => 0, 'failed_batches' => 0];

        $deleted = 0; $failed = 0;
        while (true) {
            $ids = ApiRequestLog::query()->where('created_at', '<', $cutoff)->orderBy('created_at')->limit($batchSize)->pluck('id')->all();
            if ($ids === []) break;
            try { DB::transaction(fn (): int => ApiRequestLog::query()->whereKey($ids)->delete()); $deleted += count($ids); }
            catch (\Throwable $e) { $failed++; report($e); break; }
        }
        return ['eligible' => $eligible, 'deleted' => $deleted, 'failed_batches' => $failed];
    }

    /** @return array{eligible:int,deleted:int,audit_logs_held:int,skipped_due_to_hold:bool,failed_batches:int} */
    public function cleanupAuditLogs(int $days, int $batchSize, bool $dryRun, bool $confirm): array
    {
        $this->validate($days, (int) config('retention.audit_logs.minimum_days'), (int) config('retention.audit_logs.maximum_days'), $batchSize);
        $cutoff = now()->subDays($days);
        $eligible = AuditLog::query()->where('created_at', '<', $cutoff)->count();
        if (! (bool) config('retention.audit_hold_supported')) return ['eligible'=>$eligible,'deleted'=>0,'audit_logs_held'=>$eligible,'skipped_due_to_hold'=>true,'failed_batches'=>0];
        $deletable = AuditLog::query()->where('created_at', '<', $cutoff)->whereDoesntHave('holds', fn (\Illuminate\Database\Eloquent\Builder $q) => $q->active());
        $deletableCount = $deletable->count();
        $held = $eligible - $deletableCount;
        if ($dryRun || ! $confirm) return ['eligible'=>$eligible,'deleted'=>0,'audit_logs_held'=>$held,'skipped_due_to_hold'=>$held > 0,'failed_batches'=>0];
        $deleted = 0; $failed = 0;
        while (true) {
            $ids = (clone $deletable)->orderBy('created_at')->limit($batchSize)->pluck('id')->all();
            if ($ids === []) break;
            try { DB::transaction(fn (): int => AuditLog::query()->whereKey($ids)->delete()); $deleted += count($ids); }
            catch (\Throwable $e) { $failed++; report($e); break; }
        }
        return ['eligible'=>$eligible,'deleted'=>$deleted,'audit_logs_held'=>$held,'skipped_due_to_hold'=>$held > 0,'failed_batches'=>$failed];
    }

    private function validate(int $days, int $min, int $max, int $batchSize): void
    {
        if ($days < $min || $days > $max || $batchSize < 1) throw new InvalidArgumentException('Invalid log retention configuration.');
    }
}
