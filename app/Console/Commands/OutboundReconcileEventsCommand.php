<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundEventReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Comprehensive, bounded, idempotent outbound reconciliation: unmatched
 * provider events, out-of-order provider events, terminal unmatched
 * expiry, missing delivery-attempt repair, and impossible-state
 * detection. Complements (does not replace) the narrower
 * `outbound:reconcile-stale-sending` and `outbound:reconcile-unmatched-events`
 * commands, reusing their underlying services rather than duplicating
 * matching/transition logic.
 */
final class OutboundReconcileEventsCommand extends Command
{
    protected $signature = 'outbound:reconcile-events
                            {--limit= : Maximum rows to evaluate per reconciliation phase in this run}';

    protected $description = 'Run bounded, idempotent outbound reconciliation: unmatched/out-of-order provider events, terminal unmatched expiry, attempt repair, and impossible-state detection.';

    public function handle(OutboundEventReconciliationService $service): int
    {
        $lock = Cache::lock('outbound:reconcile-events', 300);
        if (! $lock->get()) {
            $this->warn('Another reconcile-events run is in progress.');

            return self::SUCCESS;
        }

        try {
            $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
            $summary = $service->reconcile($limit);

            foreach ($summary as $label => $value) {
                $this->line($label.': '.$value);
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
