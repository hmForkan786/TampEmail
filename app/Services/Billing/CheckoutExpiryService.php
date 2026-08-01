<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingCheckoutSessionStatus;
use App\Enums\BillingOrderStatus;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use App\Services\Audit\AuditLogWriter;

final class CheckoutExpiryService
{
    public function __construct(
        private readonly BillingOrderService $orders,
        private readonly AuditLogWriter $audit,
    ) {}

    public function expire(int $batchSize): int
    {
        $count = 0;
        BillingOrder::query()
            ->whereIn('status', [BillingOrderStatus::Pending, BillingOrderStatus::Processing])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->oldest('expires_at')->limit(max(1, min(1000, $batchSize)))->get()
            ->each(function (BillingOrder $order) use (&$count): void {
                BillingCheckoutSession::query()
                    ->where('billing_order_id', $order->getKey())
                    ->whereIn('status', [BillingCheckoutSessionStatus::Created, BillingCheckoutSessionStatus::Pending, BillingCheckoutSessionStatus::Redirected])
                    ->update(['status' => BillingCheckoutSessionStatus::Expired->value, 'updated_at' => now()]);
                $expired = $this->orders->transition($order, BillingOrderStatus::Expired);
                $this->audit->write('billing.checkout.expired', $order->user_id, $expired, null, ['gateway' => $order->provider]);
                $count++;
            });

        return $count;
    }
}
