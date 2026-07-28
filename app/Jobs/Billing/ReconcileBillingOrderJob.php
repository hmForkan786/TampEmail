<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\BillingOrder;
use App\Services\Billing\BillingReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileBillingOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $billingOrderId, public readonly string $reason) {}

    public function uniqueId(): string
    {
        return 'billing-reconcile:'.$this->billingOrderId;
    }

    public function handle(BillingReconciliationService $reconciliation): void
    {
        $order = BillingOrder::query()->find($this->billingOrderId);
        if ($order === null) {
            return;
        }

        $reconciliation->markReconciliationRequired($order, $this->reason);
    }
}
