<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Enums\AttachmentScanStatus;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Email;
use App\Models\EmailProcessingLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** Read-only, payload-free operational aggregation for inbound processing. */
class InboundMetricsService
{
    /** @return array<string, array<string, mixed>> */
    public function report(?CarbonInterface $at = null): array
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return collect(['last_5_minutes' => 5, 'last_hour' => 60, 'last_24_hours' => 1440])
            ->mapWithKeys(fn (int $minutes): array => [$this->windowKey($minutes) => $this->window($at, $minutes)])
            ->all();
    }

    /** @return array<string, mixed> */
    public function health(?CarbonInterface $at = null): array
    {
        $report = $this->report($at);
        $current = $report['last_5_minutes'];
        $thresholds = config('inbound_metrics.thresholds', []);
        $breaches = [];

        if (($current['failure_rate'] ?? 0.0) > (float) ($thresholds['failure_rate'] ?? 0.10)) $breaches[] = 'failure_rate';
        if (($current['backlog']['queue'] ?? 0) > (int) ($thresholds['queue_backlog'] ?? 100)) $breaches[] = 'queue_backlog';
        if (($current['backlog']['pending_scan_oldest_minutes'] ?? 0) > (int) ($thresholds['pending_scan_age_minutes'] ?? 30)) $breaches[] = 'pending_scan_age';
        if (($current['counters']['failed'] ?? 0) >= (int) ($thresholds['retry_exhaustion'] ?? 1)) $breaches[] = 'retry_exhaustion';

        return ['status' => $breaches === [] ? 'healthy' : 'degraded', 'breaches' => $breaches, 'thresholds' => $thresholds, 'windows' => $report];
    }

    /** @return array<string, mixed> */
    private function window(CarbonInterface $at, int $minutes): array
    {
        $from = $at->copy()->subMinutes($minutes);
        $logs = EmailProcessingLog::query()->whereBetween('created_at', [$from, $at])->get(['stage', 'status', 'duration_ms', 'metadata', 'created_at']);
        $emails = Email::query()->whereBetween('received_at', [$from, $at])->count();
        $attachments = Attachment::query()->whereBetween('created_at', [$from, $at]);

        $counters = array_fill_keys(['received','queued','parsed','resolved','persisted','duplicate','rejected','failed','attachment_pending','attachment_clean','attachment_infected','attachment_failed','replayed'], 0);
        $counters['received'] = $emails;
        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $metricStage = (string) ($metadata['metric_stage'] ?? $metadata['stage_code'] ?? '');
            $classification = (string) ($metadata['classification'] ?? '');
            if ($metricStage !== 'received' && isset($counters[$metricStage])) $counters[$metricStage]++;
            if ($classification !== $metricStage && isset($counters[$classification])) $counters[$classification]++;
            if ($log->status === ProcessingLogStatus::Failed && $metricStage !== 'failed') $counters['failed']++;
            if ($log->stage === ProcessingStage::Parse && $log->status === ProcessingLogStatus::Success) $counters['parsed']++;
            if ($log->stage === ProcessingStage::StoreAttachments && $log->status === ProcessingLogStatus::Success) $counters['persisted']++;
            if ((bool) ($metadata['duplicate'] ?? false)) $counters['duplicate']++;
            if ((bool) ($metadata['rejected'] ?? false)) $counters['rejected']++;
        }
        foreach (array_keys($counters) as $counter) {
            $counters[$counter] += (int) Cache::get('inbound.metrics.counter.'.$counter, 0);
        }
        $counters['attachment_pending'] = (clone $attachments)->where('scan_status', AttachmentScanStatus::Pending)->count();
        $counters['attachment_clean'] = (clone $attachments)->where('scan_status', AttachmentScanStatus::Clean)->count();
        $counters['attachment_infected'] = (clone $attachments)->where('scan_status', AttachmentScanStatus::Infected)->count();
        $counters['attachment_failed'] = (clone $attachments)->where('scan_status', AttachmentScanStatus::Failed)->count();
        $counters['replayed'] = AuditLog::query()->where('action', 'inbound.failure_replayed')->whereBetween('created_at', [$from, $at])->count();

        $total = $emails + $logs->count();
        $failed = $counters['failed'];
        $latency = [
            'queue_delay_ms' => $this->averageMetadata($logs, 'queue_delay_ms'),
            'parse_duration_ms' => $this->averageStageDuration($logs, ProcessingStage::Parse),
            'persistence_duration_ms' => $this->averageStageDuration($logs, ProcessingStage::StoreAttachments),
            'scan_duration_ms' => $this->averageStageDuration($logs, ProcessingStage::Scan),
        ];
        $pendingOldest = (clone $attachments)->where('scan_status', AttachmentScanStatus::Pending)->min('created_at');
        $pendingOldest = $pendingOldest ? CarbonImmutable::parse($pendingOldest) : null;

        return [
            'from' => $from->toIso8601String(), 'to' => $at->toIso8601String(),
            'counters' => $counters,
            'failure_rate' => $total > 0 ? round($failed / $total, 4) : 0.0,
            'latency_ms' => $latency,
            'backlog' => ['queue' => $logs->whereIn('status', [ProcessingLogStatus::Started, ProcessingLogStatus::Retrying])->count(), 'pending_scan' => $counters['attachment_pending'], 'pending_scan_oldest_minutes' => $pendingOldest ? max(0, (int) $pendingOldest->diffInMinutes($at)) : 0],
        ];
    }

    private function windowKey(int $minutes): string { return $minutes === 5 ? 'last_5_minutes' : ($minutes === 60 ? 'last_hour' : 'last_24_hours'); }
    private function averageStageDuration($logs, ProcessingStage $stage): float { $values = $logs->filter(fn ($log): bool => $log->stage === $stage && $log->duration_ms !== null)->pluck('duration_ms'); return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 2); }
    private function averageMetadata($logs, string $key): float { $values = $logs->map(fn ($log): mixed => is_array($log->metadata) ? ($log->metadata[$key] ?? null) : null)->filter(fn ($value): bool => is_numeric($value)); return $values->isEmpty() ? 0.0 : round((float) $values->avg(), 2); }
}
