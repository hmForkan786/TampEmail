<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundDeliveryAttemptState;
use App\Enums\OutboundMessageState;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates deterministic outbound reconciliation on top of the
 * existing unmatched-event and stale-sending building blocks:
 *
 * - unmatched provider events (reuses {@see OutboundProviderEventProcessor::reconcileUnmatched()})
 * - out-of-order provider events matched to a message that had not yet
 *   reached the expected state (reuses {@see OutboundProviderEventProcessor::reconcileOutOfOrder()})
 * - terminal marking of unmatched events once they age out of the
 *   correlation window (reuses {@see OutboundProviderEventProcessor::finalizeExpiredUnmatched()})
 * - repairing missing delivery-attempt rows for otherwise-settled messages
 * - detecting impossible message-state combinations
 *
 * Every step is bounded, lock-scoped per row, and idempotent: replaying a
 * run never applies the same transition twice and never rewrites history.
 * Inconsistent messages are only ever flagged for manual review
 * (`reconciliation_flagged_at` / `reconciliation_note`) — this service
 * never guesses at an ambiguous outcome or silently overwrites a
 * previously recorded state.
 */
final class OutboundEventReconciliationService
{
    public function __construct(
        private readonly OutboundProviderEventProcessor $providerEvents,
        private readonly OutboundDeliveryAttemptRecorder $attempts,
        private readonly OutboundFailureCategoryMapper $categories,
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * @return array<string, int>
     */
    public function reconcile(?int $limit = null): array
    {
        $unmatched = $this->providerEvents->reconcileUnmatched($limit);
        $outOfOrder = $this->providerEvents->reconcileOutOfOrder($limit);
        $terminal = $this->providerEvents->finalizeExpiredUnmatched($limit);
        $repaired = $this->repairMissingDeliveryAttempts($limit);
        $impossible = $this->flagImpossibleStates($limit);

        return [
            'unmatched_evaluated' => $unmatched['evaluated'],
            'unmatched_matched' => $unmatched['matched'],
            'out_of_order_evaluated' => $outOfOrder['evaluated'],
            'out_of_order_resolved' => $outOfOrder['resolved'],
            'terminal_unmatched_evaluated' => $terminal['evaluated'],
            'terminal_unmatched' => $terminal['terminal'],
            'attempts_evaluated' => $repaired['evaluated'],
            'attempts_repaired' => $repaired['repaired'],
            'impossible_states_evaluated' => $impossible['evaluated'],
            'impossible_states_flagged' => $impossible['flagged'],
        ];
    }

    /**
     * Backfills a delivery-attempt row for the current attempt of a
     * settled message (sent/delivered/failed) when one is missing — e.g.
     * a message created before this feature shipped, or a row lost to an
     * earlier bug. Only ever backfills from the message's own already
     * persisted, safe fields (no bodies/recipients); never fabricates
     * intermediate attempt history that cannot be reconstructed.
     *
     * @return array{evaluated: int, repaired: int}
     */
    public function repairMissingDeliveryAttempts(?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('outbound.reconciliation.attempt_repair_batch_size', 100));

        $summary = ['evaluated' => 0, 'repaired' => 0];

        $candidateIds = OutboundMessage::query()
            ->whereIn('state', [
                OutboundMessageState::Sent->value,
                OutboundMessageState::Delivered->value,
                OutboundMessageState::Failed->value,
            ])
            ->where('attempt_count', '>=', 1)
            ->whereDoesntHave('deliveryAttempts', function ($query): void {
                $query->whereRaw('outbound_delivery_attempts.attempt_number = outbound_messages.attempt_count');
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidateIds as $id) {
            $summary['evaluated']++;
            if ($this->repairOne((string) $id)) {
                $summary['repaired']++;
            }
        }

        return $summary;
    }

    private function repairOne(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $message = OutboundMessage::query()->whereKey($id)->lockForUpdate()->first();
            if ($message === null || $message->attempt_count < 1) {
                return false;
            }

            $exists = OutboundDeliveryAttempt::query()
                ->where('outbound_message_id', $message->getKey())
                ->where('attempt_number', $message->attempt_count)
                ->exists();
            if ($exists) {
                return false;
            }

            [$state, $result] = match ($message->state) {
                OutboundMessageState::Sent, OutboundMessageState::Delivered => [OutboundDeliveryAttemptState::Accepted, 'accepted'],
                OutboundMessageState::Failed => [OutboundDeliveryAttemptState::PermanentFailure, 'permanent_failure'],
                default => [null, null],
            };
            if ($state === null) {
                return false;
            }

            $startedAt = $message->sending_at ?? $message->queued_at ?? $message->created_at;
            $completedAt = match ($message->state) {
                OutboundMessageState::Sent, OutboundMessageState::Delivered => $message->sent_at,
                OutboundMessageState::Failed => $message->failed_at,
                default => null,
            } ?? $message->updated_at;

            OutboundDeliveryAttempt::query()->create([
                'outbound_message_id' => $message->getKey(),
                'attempt_number' => $message->attempt_count,
                'transport' => $message->provider,
                'state' => $state->value,
                'result' => $result,
                'failure_category' => $message->state === OutboundMessageState::Failed
                    ? $this->categories->categorize($message->failure_code)
                    : null,
                'provider_message_id' => $message->provider_message_id,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'duration_ms' => $startedAt !== null && $completedAt !== null
                    ? max(0, $startedAt->diffInMilliseconds($completedAt))
                    : null,
            ]);

            $this->audit->write(
                'outbound.reconciliation_attempt_repaired',
                (string) $message->user_id,
                $message,
                null,
                null,
                [
                    'attempt' => $message->attempt_count,
                    'state' => $message->state->value,
                ],
            );

            return true;
        });
    }

    /**
     * Detects contradictory state/timestamp combinations that must never
     * occur under normal lifecycle rules and flags them for manual
     * review. Never auto-corrects — only the state-precedence-aware code
     * paths in {@see OutboundProviderEventProcessor} and
     * {@see OutboundStaleSendingReconciliationService} are trusted to
     * mutate state; this pass only detects and records.
     *
     * @return array{evaluated: int, flagged: int}
     */
    public function flagImpossibleStates(?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('outbound.reconciliation.impossible_state_batch_size', 100));

        $summary = ['evaluated' => 0, 'flagged' => 0];

        $candidateIds = OutboundMessage::query()
            ->whereNull('reconciliation_flagged_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidateIds as $id) {
            $summary['evaluated']++;
            if ($this->flagOneIfImpossible((string) $id)) {
                $summary['flagged']++;
            }
        }

        return $summary;
    }

    private function flagOneIfImpossible(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $message = OutboundMessage::query()->whereKey($id)->lockForUpdate()->first();
            if ($message === null || $message->reconciliation_flagged_at !== null) {
                return false;
            }

            $conflict = $this->detectConflict($message);
            if ($conflict === null) {
                return false;
            }

            $updated = OutboundMessage::query()
                ->whereKey($message->getKey())
                ->whereNull('reconciliation_flagged_at')
                ->update([
                    'reconciliation_flagged_at' => now(),
                    'reconciliation_note' => 'impossible_state_conflict',
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $this->audit->write(
                'outbound.reconciliation_impossible_state_detected',
                (string) $message->user_id,
                $message->fresh(),
                null,
                null,
                [
                    'state' => $message->state->value,
                    'conflict' => $conflict,
                ],
            );

            return true;
        });
    }

    /**
     * Precedence, most authoritative first (see
     * {@see OutboundMessageState::precedenceRank()}): cancelled >
     * delivered > failed > sent > sending > queued > draft. Each check
     * below detects a state/timestamp pair that violates this ordering.
     */
    private function detectConflict(OutboundMessage $message): ?string
    {
        return match (true) {
            $message->delivered_at !== null && $message->failed_at !== null => 'delivered_and_failed_both_set',
            $message->state === OutboundMessageState::Delivered && $message->sent_at === null => 'delivered_missing_sent_at',
            $message->state === OutboundMessageState::Delivered && $message->delivered_at === null => 'delivered_missing_delivered_at',
            $message->state === OutboundMessageState::Sent && $message->sent_at === null => 'sent_missing_sent_at',
            $message->state === OutboundMessageState::Failed && $message->failed_at === null => 'failed_missing_failed_at',
            $message->state === OutboundMessageState::Cancelled
                && ($message->sent_at !== null || $message->delivered_at !== null) => 'cancelled_after_send',
            in_array($message->state, [OutboundMessageState::Draft, OutboundMessageState::Queued, OutboundMessageState::Scheduled], true)
                && ($message->sent_at !== null || $message->delivered_at !== null) => 'premature_terminal_timestamp',
            default => null,
        };
    }
}
