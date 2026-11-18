<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CreateBillingOrderData;
use App\Enums\BillingActivationStatus;
use App\Enums\BillingOrderStatus;
use App\Enums\UserStatus;
use App\Exceptions\Billing\BillingOrderConflictException;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\StateMachines\BillingOrderStateMachine;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

final class BillingOrderService
{
    public function __construct(
        private readonly BillingOrderStateMachine $stateMachine,
        private readonly AuditLogWriter $audit,
    ) {}

    public function create(CreateBillingOrderData $data): BillingOrder
    {
        return DB::transaction(function () use ($data): BillingOrder {
            $existing = BillingOrder::query()
                ->where('user_id', $data->userId)
                ->where('idempotency_key', $data->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof BillingOrder) {
                if ($existing->status->isPaid()) {
                    throw new BillingOrderConflictException('A paid order already exists for this idempotency key.');
                }

                return $existing;
            }

            $user = User::query()->whereKey($data->userId)->lockForUpdate()->firstOrFail();
            if ($user->status !== UserStatus::Active) {
                throw new BillingOrderConflictException('User is not eligible for checkout.');
            }

            $plan = Plan::query()->whereKey($data->planId)->lockForUpdate()->firstOrFail();
            if (! $plan->is_active || $plan->is_free) {
                throw new BillingOrderConflictException('Plan is not available for purchase.');
            }

            $priceDecimal = match ($data->billingCycle->value) {
                'yearly' => (string) $plan->price_yearly,
                default => (string) $plan->price_monthly,
            };
            $subtotal = Money::fromDecimalString($priceDecimal, (string) $plan->currency);
            $discount = Money::zero($subtotal->currency);
            $tax = Money::zero($subtotal->currency);
            $total = $subtotal;

            $expiryMinutes = max(1, (int) config('billing.order_expiry_minutes', 30));

            $order = BillingOrder::query()->create([
                'user_id' => $data->userId,
                'plan_id' => $data->planId,
                'subscription_id' => $data->subscriptionId,
                'type' => $data->type,
                'status' => BillingOrderStatus::Pending,
                'currency' => $total->currency,
                'subtotal_minor' => $subtotal->amountMinor,
                'discount_minor' => $discount->amountMinor,
                'tax_minor' => $tax->amountMinor,
                'total_minor' => $total->amountMinor,
                'provider' => $data->provider,
                'idempotency_key' => $data->idempotencyKey,
                'expires_at' => now()->addMinutes($expiryMinutes),
                'metadata' => [
                    'billing_cycle' => $data->billingCycle->value,
                    'plan_slug' => $plan->slug,
                    'price_snapshot_decimal' => $priceDecimal,
                    'activation_status' => BillingActivationStatus::Pending->value,
                ],
            ]);

            $this->audit->write('billing.order.created', $data->userId, $order, null, [
                'status' => BillingOrderStatus::Pending->value,
                'total_minor' => $total->amountMinor,
                'currency' => $total->currency,
                'type' => $data->type->value,
            ]);

            return $order;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function transition(BillingOrder $order, BillingOrderStatus $to, array $attributes = []): BillingOrder
    {
        return DB::transaction(function () use ($order, $to, $attributes): BillingOrder {
            $locked = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->stateMachine->assertCanTransition($locked->status, $to);
            $locked->forceFill(array_merge(['status' => $to], $attributes))->save();

            return $locked->fresh();
        });
    }

    public function assertCheckoutEligible(BillingOrder $order): void
    {
        if ($order->status !== BillingOrderStatus::Pending && $order->status !== BillingOrderStatus::Processing) {
            throw new BillingOrderConflictException('Order is not eligible for checkout.');
        }

        if ($order->expires_at !== null && $order->expires_at->isPast()) {
            throw new BillingOrderConflictException('Order has expired.');
        }
    }
}
