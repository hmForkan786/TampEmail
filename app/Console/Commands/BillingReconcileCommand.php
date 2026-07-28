<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Jobs\Billing\ReconcileBillingOrderJob;
use App\Services\Billing\BillingReconciliationService;
use Illuminate\Console\Command;

final class BillingReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile {--dry-run : Report anomalies without mutating records}';

    protected $description = 'Detect billing reconciliation anomalies and enqueue recovery work';

    public function handle(BillingReconciliationService $reconciliation): int
    {
        if (! config('billing.reconciliation.enabled', true)) {
            $this->warn('Billing reconciliation is disabled.');

            return self::SUCCESS;
        }

        $batchSize = max(1, (int) config('billing.reconciliation.batch_size', 100));
        $findings = $reconciliation->detectAnomalies($batchSize);

        if ($findings->isEmpty()) {
            $this->info('No billing anomalies detected.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->line(sprintf('- [%s] order=%s', $finding['type'], $finding['billing_order_id']));

            if ($this->option('dry-run')) {
                continue;
            }

            if ($finding['type'] === 'paid_order_inactive_subscription') {
                ActivatePaidSubscriptionJob::dispatch($finding['billing_order_id'])
                    ->onQueue((string) config('billing.queues.activation', 'default'));
            } else {
                ReconcileBillingOrderJob::dispatch($finding['billing_order_id'], $finding['type'])
                    ->onQueue((string) config('billing.queues.reconciliation', 'default'));
            }
        }

        $this->info(sprintf('Processed %d finding(s).', $findings->count()));

        return self::SUCCESS;
    }
}
