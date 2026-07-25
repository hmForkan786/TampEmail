<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundUsageReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded, dry-run-first reconciliation of outbound usage reservations
 * against authoritative outbound message state. See
 * docs/OUTBOUND_USAGE_ACCOUNTING.md for the full repair policy.
 */
final class OutboundReconcileUsageCommand extends Command
{
    protected $signature = 'outbound:reconcile-usage
                            {--dry-run : Report without mutating anything (default)}
                            {--confirm : Permit deterministic repairs after policy checks}
                            {--batch= : Rows evaluated per reconciliation phase in this run}';

    protected $description = 'Reconcile outbound usage reservations/counters against authoritative message state (dry-run by default).';

    public function handle(OutboundUsageReconciliationService $service): int
    {
        $lock = Cache::lock('outbound:reconcile-usage', 300);

        if (! $lock->get()) {
            $this->warn('Another outbound usage reconcile run is in progress.');

            return self::SUCCESS;
        }

        try {
            $confirm = (bool) $this->option('confirm');
            $dryRun = (bool) $this->option('dry-run') || ! $confirm;
            $batchSize = (int) ($this->option('batch') ?: config('outbound_usage.reconcile.batch_size', 200));

            $report = $service->reconcile($dryRun, $confirm, max(1, $batchSize));

            foreach ($report as $key => $value) {
                if (is_scalar($value)) {
                    $this->line($key.': '.$value);
                }
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
