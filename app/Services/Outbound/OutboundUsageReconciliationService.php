<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundUsageReservationState;
use App\Models\OutboundUsageReservation;
use Illuminate\Support\Facades\DB;

/**
 * Bounded, dry-run-first reconciliation for outbound usage reservations.
 *
 * Repairs only deterministic inconsistencies:
 * - A `sent` message whose reservation is still `reserved` (missed commit,
 *   e.g. a crash between the message-state update and the commit call) is
 *   repaired by committing it.
 * - `reserved` rows past their TTL whose message is in a terminal state
 *   the normal release policy already covers (cancelled, or failed with
 *   no transport attempt) are released via
 *   {@see OutboundUsageService::expireReservations()}.
 *
 * Everything else (duplicate idempotency-key reservations pointing at
 * different messages, orphaned reservations, `reserved` rows past TTL
 * whose message state does not clearly justify release) is only ever
 * reported, never auto-repaired.
 */
final class OutboundUsageReconciliationService
{
    public function __construct(
        private readonly OutboundUsageService $usage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(bool $dryRun, bool $confirm, int $batchSize): array
    {
        $started = microtime(true);
        $effectiveDryRun = $dryRun || ! $confirm;

        $report = [
            'duplicate_idempotency_reservations' => $this->duplicateIdempotencyReservations(),
            'orphaned_reservations' => $this->orphanedReservations(),
        ];

        $report = array_merge($report, $this->repairMissingCommittedUsage($batchSize, $effectiveDryRun));

        $expiry = $this->usage->expireReservations($batchSize, $effectiveDryRun);
        $report['stale_reserved_scanned'] = $expiry['scanned'];
        $report['stale_reserved_released'] = $expiry['released'];
        $report['ambiguous'] = $expiry['ambiguous'];

        $report['mode'] = $effectiveDryRun ? 'dry-run' : 'confirm';
        $report['duration'] = round(microtime(true) - $started, 3);

        return $report;
    }

    /**
     * Reservations sharing the same (user_id, idempotency_key) but
     * pointing at different outbound_message_id values — indicates a bug
     * in idempotency handling, never auto-repaired.
     */
    private function duplicateIdempotencyReservations(): int
    {
        return (int) DB::table('outbound_usage_reservations')
            ->select('user_id', 'idempotency_key')
            ->groupBy('user_id', 'idempotency_key')
            ->havingRaw('COUNT(DISTINCT outbound_message_id) > 1')
            ->get()
            ->count();
    }

    private function orphanedReservations(): int
    {
        return OutboundUsageReservation::query()
            ->whereDoesntHave('outboundMessage')
            ->count();
    }

    /**
     * @return array{missing_committed_usage: int, missing_committed_usage_repaired: int}
     */
    private function repairMissingCommittedUsage(int $batchSize, bool $dryRun): array
    {
        $candidates = OutboundUsageReservation::query()
            ->where('state', OutboundUsageReservationState::Reserved->value)
            ->whereHas('outboundMessage', fn ($query) => $query->where('state', OutboundMessageState::Sent->value))
            ->limit($batchSize)
            ->get();

        $repaired = 0;

        if (! $dryRun) {
            foreach ($candidates as $reservation) {
                $this->usage->commit((string) $reservation->outbound_message_id);
                $repaired++;
            }
        }

        return [
            'missing_committed_usage' => $candidates->count(),
            'missing_committed_usage_repaired' => $repaired,
        ];
    }
}
