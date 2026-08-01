<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingActivationStatus;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use App\Enums\SubscriptionStatus;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Audit\AuditLogWriter;
use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Support\Facades\DB;

final class PaidSubscriptionActivationService
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly AuditLogWriter $audit,
    ) {}

    public function activateFromPaidOrder(string $billingOrderId): Subscription
    {
        try {
            return DB::transaction(function () use ($billingOrderId): Subscription {
                $order = BillingOrder::query()->whereKey($billingOrderId)->lockForUpdate()->firstOrFail();

                if ($order->status !== BillingOrderStatus::Paid) {
                    throw new \RuntimeException('Only paid orders can activate subscriptions.');
                }

                $metadata = $order->metadata ?? [];
                $activationStatus = $metadata['activation_status'] ?? BillingActivationStatus::Pending->value;

                if ($activationStatus === BillingActivationStatus::Succeeded->value && $order->subscription_id !== null) {
                    return Subscription::query()->findOrFail($order->subscription_id);
                }

                $billingCycle = BillingCycle::from((string) ($metadata['billing_cycle'] ?? BillingCycle::Monthly->value));
                $subscription = $this->resolveSubscription($order);
                $startsAt = now();
                $termStartsAt = $order->type === BillingOrderType::Renewal
                    && $subscription->ends_at?->isFuture()
                        ? $subscription->ends_at->copy()
                        : $startsAt;
                $endsAt = match ($billingCycle) {
                    BillingCycle::Yearly => $termStartsAt->copy()->addYear(),
                    BillingCycle::Lifetime => $termStartsAt->copy()->addYears(100),
                    default => $termStartsAt->copy()->addMonth(),
                };

                if ($order->type === BillingOrderType::Renewal) {
                    $this->lifecycle->renew($subscription, $endsAt, $order->user_id, 'billing');
                } else {
                    $this->lifecycle->activate($subscription, $startsAt, $endsAt, $order->user_id, 'billing');
                }

                $metadata['activation_status'] = BillingActivationStatus::Succeeded->value;
                $order->forceFill([
                    'subscription_id' => $subscription->getKey(),
                    'metadata' => $metadata,
                ])->save();

                $this->audit->write('billing.subscription.activated', $order->user_id, $subscription, null, [
                    'billing_order_id' => $order->getKey(),
                ]);

                return $subscription->fresh();
            });
        } catch (\Throwable $exception) {
            $this->recordActivationFailure($billingOrderId, $exception);

            throw $exception;
        }
    }

    private function recordActivationFailure(string $billingOrderId, \Throwable $exception): void
    {
        DB::transaction(function () use ($billingOrderId, $exception): void {
            $order = BillingOrder::query()->whereKey($billingOrderId)->lockForUpdate()->first();
            if ($order === null) {
                return;
            }

            $metadata = $order->metadata ?? [];
            $metadata['activation_status'] = BillingActivationStatus::Failed->value;
            $metadata['activation_error'] = $exception->getMessage();
            $order->forceFill(['metadata' => $metadata])->save();

            $this->audit->write('billing.subscription.activation_failed', $order->user_id, $order, null, [
                'error' => $exception->getMessage(),
            ]);
        });
    }

    private function resolveSubscription(BillingOrder $order): Subscription
    {
        if ($order->subscription_id !== null) {
            return Subscription::query()->whereKey($order->subscription_id)->lockForUpdate()->firstOrFail();
        }

        if ($order->type === BillingOrderType::Renewal) {
            $existing = Subscription::query()
                ->where('user_id', $order->user_id)
                ->where('plan_id', $order->plan_id)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Subscription) {
                return $existing;
            }
        }

        $plan = Plan::query()->findOrFail($order->plan_id);
        $metadata = $order->metadata ?? [];
        $billingCycle = BillingCycle::from((string) ($metadata['billing_cycle'] ?? BillingCycle::Monthly->value));
        $priceDecimal = (string) ($metadata['price_snapshot_decimal'] ?? $plan->price_monthly);

        return Subscription::query()->create([
            'user_id' => $order->user_id,
            'plan_id' => $order->plan_id,
            'status' => SubscriptionStatus::Cancelled,
            'billing_cycle' => $billingCycle,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subHour(),
            'auto_renew' => true,
            'price' => $priceDecimal,
            'currency' => $order->currency,
            'metadata' => ['source_billing_order_id' => $order->getKey()],
        ]);
    }
}
