<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\DTOs\Billing\CreateBillingOrderData;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderType;
use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionLifecycleEvent;
use App\Models\Subscription;
use App\Services\Billing\BillingOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates scheduled renewal work through the existing order and lifecycle services.
 */
final class SubscriptionRenewalScheduler
{
    public function __construct(
        private readonly BillingOrderService $orders,
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    /** @return array{processed:int,skipped:int} */
    public function createRenewalOrders(): array
    {
        $this->assertConfiguration();
        $leadDays = $this->boundedConfig('renewal_lead_days', 1, 30);
        $batchSize = $this->boundedConfig('batch_size', 1, 1000);
        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('auto_renew', true)
            ->where('cancel_at_period_end', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays($leadDays))
            ->orderBy('ends_at')
            ->limit($batchSize)
            ->get();

        $result = ['processed' => 0, 'skipped' => 0];
        foreach ($subscriptions as $subscription) {
            if ($subscription->billing_cycle === BillingCycle::Lifetime) {
                $result['skipped']++;

                continue;
            }

            $key = sprintf('renewal:%s:%d', $subscription->getKey(), $subscription->ends_at->getTimestamp());
            $order = $this->orders->create(new CreateBillingOrderData(
                userId: $subscription->user_id,
                planId: $subscription->plan_id,
                type: BillingOrderType::Renewal,
                billingCycle: $subscription->billing_cycle,
                idempotencyKey: $key,
                subscriptionId: (string) $subscription->getKey(),
            ));

            $graceDays = $this->boundedConfig('grace_days', 1, 90);
            $order->forceFill(['expires_at' => $subscription->ends_at->copy()->addDays($graceDays)])->save();
            $this->lifecycle->markRenewalDue($subscription);
            $result['processed']++;
        }

        return $result;
    }

    /** @return array{processed:int,reminded:int,skipped:int} */
    public function startGracePeriods(): array
    {
        $this->assertConfiguration();
        $graceDays = $this->boundedConfig('grace_days', 1, 90);
        $batchSize = $this->boundedConfig('batch_size', 1, 1000);
        $result = ['processed' => 0, 'reminded' => 0, 'skipped' => 0];

        $due = Subscription::query()
            ->where('status', SubscriptionStatus::RenewalDue)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->limit($batchSize)
            ->get();

        foreach ($due as $subscription) {
            if (! $subscription->auto_renew || $subscription->cancel_at_period_end) {
                $this->lifecycle->expireNow($subscription, source: 'scheduler');
                $result['skipped']++;

                continue;
            }

            $this->lifecycle->startGrace($subscription, $subscription->ends_at->copy()->addDays($graceDays));
            $result['processed']++;
        }

        $today = now()->toDateString();
        $graceSubscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Grace)
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
        foreach ($graceSubscriptions as $subscription) {
            $metadata = $subscription->metadata ?? [];
            $graceEndsAt = isset($metadata['grace_ends_at']) ? CarbonImmutable::parse((string) $metadata['grace_ends_at']) : null;
            if ($graceEndsAt === null || $graceEndsAt->lessThanOrEqualTo(now()) || ($metadata['grace_reminder_date'] ?? null) === $today) {
                continue;
            }

            DB::transaction(function () use ($subscription, $today): void {
                $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
                $metadata = $locked->metadata ?? [];
                if ($locked->status !== SubscriptionStatus::Grace || ($metadata['grace_reminder_date'] ?? null) === $today) {
                    return;
                }
                $metadata['grace_reminder_date'] = $today;
                $locked->forceFill(['metadata' => $metadata])->save();
                SubscriptionLifecycleEvent::dispatch('grace_reminder', $locked->fresh());
            });
            $result['reminded']++;
        }

        return $result;
    }

    /** @return array{processed:int,skipped:int} */
    public function expireSubscriptions(): array
    {
        $this->assertConfiguration();
        $batchSize = $this->boundedConfig('batch_size', 1, 1000);
        $candidates = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Trial, SubscriptionStatus::Grace])
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
        $result = ['processed' => 0, 'skipped' => 0];

        foreach ($candidates as $subscription) {
            $boundary = $subscription->status === SubscriptionStatus::Trial
                ? ($subscription->trial_ends_at ?? $subscription->ends_at)
                : $this->graceEndsAt($subscription);
            if ($boundary === null || $boundary->isAfter(now())) {
                $result['skipped']++;

                continue;
            }
            $this->lifecycle->expireNow($subscription, source: 'scheduler');
            $result['processed']++;
        }

        return $result;
    }

    private function graceEndsAt(Subscription $subscription): ?CarbonImmutable
    {
        $value = ($subscription->metadata ?? [])['grace_ends_at'] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function boundedConfig(string $key, int $minimum, int $maximum): int
    {
        $value = config("billing.lifecycle.{$key}");
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \RuntimeException("Invalid billing lifecycle configuration: {$key}.");
        }

        return $value;
    }

    private function assertConfiguration(): void
    {
        $this->boundedConfig('grace_days', 1, 90);
        $this->boundedConfig('renewal_lead_days', 1, 30);
        $this->boundedConfig('trial_days', 1, 90);
        $this->boundedConfig('batch_size', 1, 1000);
    }
}
