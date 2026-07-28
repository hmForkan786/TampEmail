<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\BillingOrder;
use App\Services\Billing\PaymentStatusSynchronizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncPaymentStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $billingOrderId) {}

    public function uniqueId(): string
    {
        return 'billing-payment-sync:'.$this->billingOrderId;
    }

    public function handle(PaymentStatusSynchronizationService $sync): void
    {
        $order = BillingOrder::query()->find($this->billingOrderId);
        if ($order instanceof BillingOrder) {
            $sync->sync($order);
        }
    }
}
