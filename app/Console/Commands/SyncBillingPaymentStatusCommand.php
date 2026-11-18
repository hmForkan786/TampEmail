<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingOrder;
use App\Services\Billing\PaymentStatusSynchronizationService;
use Illuminate\Console\Command;

final class SyncBillingPaymentStatusCommand extends Command
{
    protected $signature = 'billing:sync-payment-status {--order=} {--provider=} {--stale-minutes=} {--limit=} {--dry-run}';

    protected $description = 'Synchronize provider payment status for one order or a bounded stale batch';

    public function handle(PaymentStatusSynchronizationService $sync): int
    {
        $orderId = $this->option('order');
        if (is_string($orderId) && $orderId !== '') {
            $order = BillingOrder::query()->findOrFail($orderId);
            if (! $this->option('dry-run')) {
                $sync->sync($order);
            }
            $this->info('Selected 1 billing order.');

            return self::SUCCESS;
        }
        $limit = (int) ($this->option('limit') ?: config('billing.payment_sync.batch_size', 100));
        $stale = (int) ($this->option('stale-minutes') ?: config('billing.payment_sync.stale_after_minutes', 10));
        if ($this->option('dry-run')) {
            $this->info('Dry-run: no provider query performed.');

            return self::SUCCESS;
        }
        $this->info('Synchronized '.$sync->syncStale($limit, $stale).' billing order(s).');

        return self::SUCCESS;
    }
}
