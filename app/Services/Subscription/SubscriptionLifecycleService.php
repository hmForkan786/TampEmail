<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Events\SubscriptionLifecycleEvent;
use App\Exceptions\SubscriptionLifecycleConflictException;
use App\Models\Subscription;
use App\Services\Audit\AuditLogWriter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SubscriptionLifecycleService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function activate(Subscription $subscription, CarbonInterface $startsAt, ?CarbonInterface $endsAt = null, ?string $actorId = null, string $source = 'domain'): Subscription
    {
        return $this->start($subscription, SubscriptionStatus::Active, $startsAt, $endsAt, null, $actorId, $source);
    }

    public function startTrial(Subscription $subscription, CarbonInterface $startsAt, CarbonInterface $trialEndsAt, ?string $actorId = null, string $source = 'domain'): Subscription
    {
        return $this->start($subscription, SubscriptionStatus::Trial, $startsAt, $trialEndsAt, $trialEndsAt, $actorId, $source);
    }

    public function cancelImmediately(Subscription $subscription, ?string $actorId = null, string $source = 'domain'): Subscription
    {
        return DB::transaction(function () use ($subscription, $actorId, $source): Subscription {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === SubscriptionStatus::Cancelled && ! $locked->cancel_at_period_end) {
                return $locked;
            }
            $this->assertStatus($locked, [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::RenewalDue, SubscriptionStatus::Grace]);
            $at = now();
            $old = $locked->status;
            $locked->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $at, 'cancel_at_period_end' => false, 'auto_renew' => false, 'ends_at' => $at])->save();
            $this->auditTransition('subscription.cancelled', $locked, $old, SubscriptionStatus::Cancelled, $actorId, $source, $at);

            return $locked->fresh();
        });
    }

    public function cancelAtPeriodEnd(Subscription $subscription, ?string $actorId = null, string $source = 'domain'): Subscription
    {
        return DB::transaction(function () use ($subscription, $actorId, $source): Subscription {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            $this->assertStatus($locked, [SubscriptionStatus::Active, SubscriptionStatus::Trial]);
            if ($locked->ends_at === null || $locked->ends_at->lessThanOrEqualTo(now())) {
                throw new SubscriptionLifecycleConflictException('Period-end cancellation requires a future ends_at.');
            }
            if ($locked->cancel_at_period_end) {
                return $locked;
            }
            $at = now();
            $locked->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => $at, 'auto_renew' => false])->save();
            $this->audit->write('subscription.cancel_requested', $actorId, $locked, ['cancel_at_period_end' => false], ['cancel_at_period_end' => true], ['source' => $source, 'effective_at' => $locked->ends_at->toIso8601String()], $at);

            return $locked->fresh();
        });
    }

    public function expireNow(Subscription $subscription, ?string $actorId = null, string $source = 'domain'): Subscription
    {
        return DB::transaction(function () use ($subscription, $actorId, $source): Subscription {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === SubscriptionStatus::Expired) {
                return $locked;
            }
            $this->assertStatus($locked, [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::RenewalDue, SubscriptionStatus::Grace]);
            $old = $locked->status;
            $at = now();
            $locked->forceFill(['status' => SubscriptionStatus::Expired, 'auto_renew' => false, 'cancel_at_period_end' => false, 'ends_at' => $at])->save();
            $this->auditTransition('subscription.expired', $locked, $old, SubscriptionStatus::Expired, $actorId, $source, $at);
            SubscriptionLifecycleEvent::dispatch('expired', $locked->fresh(), ['previous_status' => $old->value]);

            return $locked->fresh();
        });
    }

    public function markRenewalDue(Subscription $subscription, ?string $actorId = null, string $source = 'scheduler'): Subscription
    {
        return $this->transition(
            $subscription,
            SubscriptionStatus::RenewalDue,
            [SubscriptionStatus::Active],
            'subscription.renewal_due',
            'renewal_due',
            $actorId,
            $source,
        );
    }

    public function startGrace(Subscription $subscription, CarbonInterface $graceEndsAt, ?string $actorId = null, string $source = 'scheduler'): Subscription
    {
        if ($graceEndsAt->lessThanOrEqualTo(now())) {
            throw new SubscriptionLifecycleConflictException('Grace period must end in the future.');
        }

        return $this->transition(
            $subscription,
            SubscriptionStatus::Grace,
            [SubscriptionStatus::RenewalDue],
            'subscription.grace_started',
            'grace_started',
            $actorId,
            $source,
            ['grace_started_at' => now()->toIso8601String(), 'grace_ends_at' => $graceEndsAt->toIso8601String()],
        );
    }

    public function renew(Subscription $subscription, CarbonInterface $newEndsAt, ?string $actorId = null, string $source = 'domain'): Subscription
    {
        return DB::transaction(function () use ($subscription, $newEndsAt, $actorId, $source): Subscription {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === SubscriptionStatus::Active && $locked->ends_at?->getTimestamp() === $newEndsAt->getTimestamp()) {
                return $locked;
            }
            if ($locked->ends_at !== null && $newEndsAt->lessThanOrEqualTo($locked->ends_at)) {
                throw new SubscriptionLifecycleConflictException('Renewal must extend the current term.');
            }
            if ($newEndsAt->lessThanOrEqualTo(now()) || ! $locked->plan()->where('is_active', true)->exists()) {
                throw new SubscriptionLifecycleConflictException('Renewal requires a future end date and active plan.');
            }
            $old = $locked->status;
            $at = now();
            $metadata = $locked->metadata ?? [];
            unset($metadata['grace_started_at'], $metadata['grace_ends_at']);
            $locked->forceFill(['status' => SubscriptionStatus::Active, 'starts_at' => $at, 'ends_at' => $newEndsAt, 'trial_ends_at' => null, 'cancelled_at' => null, 'cancel_at_period_end' => false, 'metadata' => $metadata])->save();
            $action = $old === SubscriptionStatus::Active ? 'subscription.renewed' : 'subscription.reactivated';
            $this->auditTransition($action, $locked, $old, SubscriptionStatus::Active, $actorId, $source, $at);
            SubscriptionLifecycleEvent::dispatch('subscription_recovered', $locked->fresh(), ['previous_status' => $old->value]);

            return $locked->fresh();
        });
    }

    /** @param list<SubscriptionStatus> $allowed
     * @param  array<string, mixed>  $metadata
     */
    private function transition(
        Subscription $subscription,
        SubscriptionStatus $to,
        array $allowed,
        string $auditAction,
        string $eventName,
        ?string $actorId,
        string $source,
        array $metadata = [],
    ): Subscription {
        return DB::transaction(function () use ($subscription, $to, $allowed, $auditAction, $eventName, $actorId, $source, $metadata): Subscription {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === $to) {
                return $locked;
            }
            $this->assertStatus($locked, $allowed);
            $old = $locked->status;
            $at = now();
            $locked->forceFill([
                'status' => $to,
                'metadata' => array_merge($locked->metadata ?? [], $metadata),
            ])->save();
            $this->auditTransition($auditAction, $locked, $old, $to, $actorId, $source, $at);
            SubscriptionLifecycleEvent::dispatch($eventName, $locked->fresh(), ['previous_status' => $old->value]);

            return $locked->fresh();
        });
    }

    private function start(Subscription $subscription, SubscriptionStatus $status, CarbonInterface $startsAt, ?CarbonInterface $endsAt, ?CarbonInterface $trialEndsAt, ?string $actorId, string $source): Subscription
    {
        return DB::transaction(function () use ($subscription, $status, $startsAt, $endsAt, $trialEndsAt, $actorId, $source): Subscription {
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();
            if ($endsAt !== null && ($endsAt->lessThan($startsAt) || $endsAt->lessThanOrEqualTo(now()))) {
                throw new SubscriptionLifecycleConflictException('Subscription term dates are invalid.');
            }
            if (! $locked->plan()->where('is_active', true)->exists() || ! $locked->user()->where('status', UserStatus::Active)->exists()) {
                throw new SubscriptionLifecycleConflictException('Activation requires an active user and plan.');
            }
            $allowed = $status === SubscriptionStatus::Trial
                ? [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired]
                : [SubscriptionStatus::Trial, SubscriptionStatus::Cancelled, SubscriptionStatus::Expired, SubscriptionStatus::Active];
            if ($status === SubscriptionStatus::Trial && ! in_array($locked->status, $allowed, true)) {
                throw new SubscriptionLifecycleConflictException('Invalid trial transition.');
            }
            $overlap = Subscription::query()->where('user_id', $locked->user_id)->whereKeyNot($locked->getKey())
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
                ->where('starts_at', '<', $endsAt ?? now()->addYears(100))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt))->lockForUpdate()->exists();
            if ($overlap) {
                throw new SubscriptionLifecycleConflictException('An overlapping access-granting subscription exists.');
            }
            if ($locked->status === $status
                && $locked->starts_at->getTimestamp() === $startsAt->getTimestamp()
                && $locked->ends_at?->getTimestamp() === $endsAt?->getTimestamp()) {
                return $locked;
            }
            $old = $locked->status;
            $locked->forceFill(['status' => $status, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'trial_ends_at' => $trialEndsAt, 'cancelled_at' => null, 'cancel_at_period_end' => false])->save();
            $this->auditTransition($status === SubscriptionStatus::Trial ? 'subscription.trial_started' : 'subscription.activated', $locked, $old, $status, $actorId, $source, now());

            return $locked->fresh();
        });
    }

    /** @param list<SubscriptionStatus> $allowed */
    private function assertStatus(Subscription $subscription, array $allowed): void
    {
        if (! in_array($subscription->status, $allowed, true)) {
            throw new SubscriptionLifecycleConflictException('Invalid subscription lifecycle transition.');
        }
    }

    private function auditTransition(string $action, Subscription $subscription, SubscriptionStatus $old, SubscriptionStatus $new, ?string $actorId, string $source, CarbonInterface $at): void
    {
        $this->audit->write($action, $actorId, $subscription, ['status' => $old->value], ['status' => $new->value], ['source' => $source, 'user_id' => $subscription->user_id, 'plan_id' => $subscription->plan_id, 'effective_at' => $at->toIso8601String()], $at);
    }
}
