<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\OutboundMessage;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles outbound messages stuck in `sending` because their delivery
 * worker died mid-attempt (crash, OOM kill, forced restart).
 *
 * Safety contract:
 * - Requeue is only performed when the transport was never invoked for the
 *   stuck attempt (`transport_attempted_at` is null). No submission ever
 *   reached the provider, so resubmission carries no duplicate-send risk.
 * - Once the transport has been invoked, the outcome is ambiguous: the
 *   provider may have already accepted the message. The message is left in
 *   `sending` and flagged for manual review; it must never be automatically
 *   failed or resent. A later provider event or admin action resolves it.
 */
final class OutboundStaleSendingReconciliationService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly OutboundDeliveryAttemptRecorder $attempts,
    ) {}

    /**
     * @return array{evaluated: int, requeued: int, flagged_ambiguous: int, failed_exhausted: int, skipped: int}
     */
    public function reconcile(?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('outbound.reconciliation.stale_sending_batch_size', 50));
        $thresholdSeconds = max(1, (int) config('outbound.reconciliation.stale_sending_threshold_seconds', 900));
        $cutoff = now()->subSeconds($thresholdSeconds);

        $summary = ['evaluated' => 0, 'requeued' => 0, 'flagged_ambiguous' => 0, 'failed_exhausted' => 0, 'skipped' => 0];

        $candidates = OutboundMessage::query()
            ->where('state', OutboundMessageState::Sending->value)
            ->where('sending_at', '<=', $cutoff)
            ->orderBy('sending_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidates as $id) {
            $outcome = $this->reconcileOne((string) $id, $cutoff);
            if ($outcome === null) {
                continue;
            }
            $summary['evaluated']++;
            $summary[$outcome]++;

            // Dispatched after the row's transaction commits so the queue
            // never observes a `queued` message before it is visible.
            if ($outcome === 'requeued') {
                DeliverOutboundMessageJob::dispatch((string) $id);
            }
        }

        return $summary;
    }

    /**
     * @return 'requeued'|'flagged_ambiguous'|'failed_exhausted'|'skipped'|null
     */
    private function reconcileOne(string $id, \DateTimeInterface $cutoff): ?string
    {
        return DB::transaction(function () use ($id, $cutoff): ?string {
            $message = OutboundMessage::query()->whereKey($id)->lockForUpdate()->first();

            if ($message === null || $message->state !== OutboundMessageState::Sending) {
                return null;
            }
            if ($message->sending_at === null || $message->sending_at->greaterThan($cutoff)) {
                return null;
            }

            // Already flagged ambiguous; leave untouched until a provider
            // event or admin action resolves it. Never re-flag repeatedly.
            if ($message->reconciliation_flagged_at !== null) {
                return 'skipped';
            }

            if ($message->transport_attempted_at !== null) {
                return $this->flagAmbiguous($message);
            }

            $maxAttempts = max(1, (int) config('outbound.send_max_attempts', 3));
            if ($message->attempt_count >= $maxAttempts) {
                return $this->failExhausted($message);
            }

            return $this->requeue($message);
        });
    }

    private function requeue(OutboundMessage $message): string
    {
        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sending->value)
            ->update([
                'state' => OutboundMessageState::Queued->value,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return 'skipped';
        }

        $this->audit->write(
            'outbound.stale_sending_requeued',
            (string) $message->user_id,
            $message->fresh(),
            ['state' => OutboundMessageState::Sending->value],
            ['state' => OutboundMessageState::Queued->value],
            [
                'attempt' => $message->attempt_count,
                'stale_seconds' => $message->sending_at !== null ? now()->diffInSeconds($message->sending_at) : null,
            ],
        );

        return 'requeued';
    }

    private function failExhausted(OutboundMessage $message): string
    {
        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sending->value)
            ->update([
                'state' => OutboundMessageState::Failed->value,
                'failed_at' => now(),
                'failure_code' => 'stale_sending_attempts_exhausted',
                'failure_message' => 'Delivery worker did not complete within the retry budget.',
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return 'skipped';
        }

        $this->audit->write(
            'outbound.stale_sending_failed_exhausted',
            (string) $message->user_id,
            $message->fresh(),
            ['state' => OutboundMessageState::Sending->value],
            ['state' => OutboundMessageState::Failed->value],
            ['attempt' => $message->attempt_count],
        );

        return 'failed_exhausted';
    }

    private function flagAmbiguous(OutboundMessage $message): string
    {
        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sending->value)
            ->whereNull('reconciliation_flagged_at')
            ->update([
                'reconciliation_flagged_at' => now(),
                'reconciliation_note' => 'ambiguous_transport_outcome',
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return 'skipped';
        }

        $this->attempts->markAmbiguous($message);

        $this->audit->write(
            'outbound.stale_sending_flagged_ambiguous',
            (string) $message->user_id,
            $message->fresh(),
            null,
            null,
            [
                'attempt' => $message->attempt_count,
                'reason' => 'transport_attempted_before_worker_loss',
            ],
        );

        return 'flagged_ambiguous';
    }
}
