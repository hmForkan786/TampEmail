<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Models\EmailProcessingLog;
use Illuminate\Support\Facades\Cache;

/** Best-effort safe lifecycle instrumentation. */
final class InboundMetricsRecorder
{
    /** @param array<string, scalar|null> $metadata */
    public function record(?string $emailId, string $code, ProcessingStage $stage = ProcessingStage::Receive, ProcessingLogStatus $status = ProcessingLogStatus::Success, array $metadata = []): void
    {
        try {
            $safe = array_intersect_key($metadata, array_flip(['metric_stage','classification','queue_delay_ms','duration_ms','attempts','retryable','recipient_code','transaction']));
            $safe['metric_stage'] = $code;
            if ($emailId !== null && $emailId !== '') {
                $existing = EmailProcessingLog::query()->where('email_id', $emailId)->where('stage', $stage)->where('status', $status)->where('metadata->metric_stage', $code)->exists();
                if (! $existing) EmailProcessingLog::query()->create(['email_id' => $emailId, 'stage' => $stage, 'status' => $status, 'worker' => 'inbound-metrics', 'duration_ms' => isset($safe['duration_ms']) ? (int) $safe['duration_ms'] : null, 'metadata' => $safe]);
            } else {
                Cache::increment('inbound.metrics.counter.'.$code);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
