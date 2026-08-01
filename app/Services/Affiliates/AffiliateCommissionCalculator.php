<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionType;
use App\Models\AffiliateCommissionPlan;
use App\Models\BillingOrder;

/**
 * Computes affiliate commission amounts from a plan and a qualifying order.
 *
 * Money is always integer minor units; percentage math floors toward zero
 * and never produces a negative or fractional result.
 */
final class AffiliateCommissionCalculator
{
    public function calculate(BillingOrder $order, AffiliateCommissionPlan $plan): int
    {
        if ($plan->currency !== null && strtoupper($plan->currency) !== strtoupper($order->currency)) {
            return 0;
        }

        if ($plan->minimum_order_minor !== null && $order->total_minor < $plan->minimum_order_minor) {
            return 0;
        }

        $base = $this->resolveBase($order);

        $commission = match ($plan->commission_type) {
            AffiliateCommissionType::Percentage => intdiv($base * (int) $plan->percentage_bps, 10000),
            AffiliateCommissionType::Fixed => (int) $plan->fixed_amount_minor,
        };

        if ($plan->maximum_commission_minor !== null) {
            $commission = min($commission, $plan->maximum_commission_minor);
        }

        return max(0, $commission);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotPlan(AffiliateCommissionPlan $plan): array
    {
        return [
            'id' => $plan->getKey(),
            'name' => $plan->name,
            'commission_type' => $plan->commission_type->value,
            'percentage_bps' => $plan->percentage_bps,
            'fixed_amount_minor' => $plan->fixed_amount_minor,
            'currency' => $plan->currency,
            'minimum_order_minor' => $plan->minimum_order_minor,
            'maximum_commission_minor' => $plan->maximum_commission_minor,
            'cookie_window_days' => $plan->cookie_window_days,
            'commission_hold_days' => $plan->commission_hold_days,
            'new_customer_only' => $plan->new_customer_only,
            'recurring_commission_enabled' => $plan->recurring_commission_enabled,
            'recurring_cycles' => $plan->recurring_cycles,
            'snapshotted_at' => now()->toIso8601String(),
        ];
    }

    private function resolveBase(BillingOrder $order): int
    {
        return match (config('affiliates.commission_base', 'subtotal_after_discount')) {
            'total' => $order->total_minor,
            'subtotal' => $order->subtotal_minor,
            default => max(0, $order->subtotal_minor - $order->discount_minor),
        };
    }
}
