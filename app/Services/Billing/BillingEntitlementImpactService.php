<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingOrder;
use App\Models\Subscription;
use App\Services\Subscription\SubscriptionLifecycleService;

final class BillingEntitlementImpactService
{
    public function __construct(private readonly SubscriptionLifecycleService $lifecycle) {}

    public function applyChargeback(BillingOrder $order): void
    {
        if ($order->subscription_id === null) {
            return;
        }

        $subscription = $order->subscription;
        if (! $subscription instanceof Subscription) {
            return;
        }

        $this->lifecycle->cancelImmediately($subscription, null, 'billing_chargeback');
    }

    public function applyFullRefund(BillingOrder $order): void
    {
        if ($order->subscription_id === null) {
            return;
        }

        $subscription = $order->subscription;
        if (! $subscription instanceof Subscription) {
            return;
        }

        $this->lifecycle->cancelImmediately($subscription, null, 'billing_refund');
    }
}
