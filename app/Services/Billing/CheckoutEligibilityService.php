<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CheckoutEligibilityResult;
use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\ValueObjects\Money;
use Throwable;

final class CheckoutEligibilityService
{
    public function evaluate(User $user, Plan $target): CheckoutEligibilityResult
    {
        if ($user->status !== UserStatus::Active) {
            return $this->deny($target, 'billing_unavailable');
        }
        if (! $target->is_active || (($target->metadata['public'] ?? true) !== true)) {
            return $this->deny($target, 'plan_not_available');
        }
        if ($target->is_free || (($target->metadata['purchasable'] ?? true) !== true)) {
            return $this->deny($target, 'plan_not_purchasable');
        }
        try {
            $monthly = Money::fromDecimalString((string) $target->price_monthly, (string) $target->currency);
            $yearly = Money::fromDecimalString((string) $target->price_yearly, (string) $target->currency);
            if ($monthly->amountMinor < 1 || $yearly->amountMinor < 1) {
                return $this->deny($target, 'invalid_plan_price');
            }
        } catch (Throwable) {
            return $this->deny($target, 'invalid_plan_price');
        }

        $subscription = Subscription::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
            ->latest('starts_at')
            ->first();

        $type = BillingOrderType::Purchase;
        if ($subscription instanceof Subscription) {
            if ($subscription->plan_id === $target->getKey()) {
                if (! config('billing.checkout.allow_same_plan_renewal', true)) {
                    return $this->deny($target, 'already_subscribed', $subscription);
                }
                $type = BillingOrderType::Renewal;
            } else {
                $current = $subscription->plan;
                if ($target->display_order < $current->display_order) {
                    return $this->deny($target, 'downgrade_not_supported', $subscription);
                }
                $type = BillingOrderType::Upgrade;
            }
        }

        $pending = BillingOrder::query()
            ->where('user_id', $user->getKey())
            ->where('plan_id', $target->getKey())
            ->whereIn('status', [BillingOrderStatus::Pending, BillingOrderStatus::Processing])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->first();

        return new CheckoutEligibilityResult(
            eligible: true,
            orderType: $type,
            reasonCode: null,
            currentPlanId: $subscription?->plan_id,
            targetPlanId: (string) $target->getKey(),
            existingOrderId: $pending?->getKey(),
            recommendedAction: $pending ? 'resume' : 'create',
            subscriptionId: $subscription?->getKey(),
        );
    }

    private function deny(Plan $target, string $code, ?Subscription $subscription = null): CheckoutEligibilityResult
    {
        return new CheckoutEligibilityResult(false, null, $code, $subscription?->plan_id, (string) $target->getKey());
    }
}
