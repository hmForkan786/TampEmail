<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundUsageReservationState;
use App\Enums\ResetPeriod;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Feature;
use App\Models\OutboundMessage;
use App\Models\OutboundUsageReservation;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Atomic outbound usage reservation lifecycle bridging outbound
 * send/reply/forward activity to subscription plan entitlements and
 * SubscriptionUsage counters.
 *
 * Fully independent from {@see OutboundRateLimiter}, which enforces abuse
 * thresholds. This service enforces *billing/entitlement* allowance:
 * message count, recipient count, and attachment bytes per reset period.
 *
 * Accounting model (see docs/OUTBOUND_USAGE_ACCOUNTING.md):
 * - `reserve()` locks the relevant SubscriptionUsage row(s), checks
 *   `used_value + outstanding reserved units + new units <= limit_value`,
 *   and inserts a `reserved` row. It does NOT increment `used_value`.
 * - `commit()` (provider acceptance / sent) increments `used_value` for
 *   every dimension that had a limit at reserve time and flips the
 *   reservation to `committed` (terminal).
 * - `release()` flips a `reserved` row to `released` (terminal) WITHOUT
 *   ever touching `used_value` — used only for pre-transport-attempt
 *   outcomes (cancel while queued, authorization/validation failure
 *   before the transport call, or the enclosing DB transaction rolling
 *   back entirely).
 * - Failures *after* a transport attempt (permanent rejection, retry
 *   exhaustion) never release: quota is spent by the attempt regardless
 *   of transport outcome, so the reservation is left `reserved`
 *   indefinitely (a safety-net reconcile pass may flag, never
 *   auto-release, these).
 *
 * A plan missing a metered feature entirely is treated as UNLIMITED for
 * that dimension (never a fallback to `free_defaults` config) so existing
 * send/reply/forward tests that only attach the boolean
 * `send_email`/`reply_email`/`forward_email` features keep passing
 * unmodified.
 */
final class OutboundUsageService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditLogWriter $audit,
    ) {}

    public function meteringEnabled(): bool
    {
        return (bool) config('outbound_usage.metering_enabled', true);
    }

    /**
     * Reserve outbound usage allowance for a freshly created message.
     *
     * Idempotent per outbound message: a second call for the same message
     * id returns the existing reservation without re-checking or
     * double-reserving. Intended to be called inside the same DB
     * transaction that inserts the outbound message row, so a quota
     * exception rolls the message insert back too.
     */
    public function reserve(User $user, OutboundMessage $message, string $idempotencyKey, int $attachmentBytes = 0): ?OutboundUsageReservation
    {
        if (! $this->meteringEnabled()) {
            return null;
        }

        $existing = OutboundUsageReservation::query()
            ->where('outbound_message_id', $message->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $subscription = $this->entitlements->currentSubscription($user);
        $recipientUnits = max(0, $message->recipientCount());
        $attachmentBytes = max(0, $attachmentBytes);

        return DB::transaction(function () use ($message, $idempotencyKey, $user, $subscription, $recipientUnits, $attachmentBytes): OutboundUsageReservation {
            $usageRows = $this->checkAllowance($user, $subscription, $recipientUnits, $attachmentBytes, lock: true);

            $usageIds = [];
            foreach ($usageRows as $dimension => $usage) {
                $usageIds[$dimension] = (string) $usage->getKey();
            }

            try {
                return OutboundUsageReservation::query()->create([
                    'outbound_message_id' => $message->getKey(),
                    'user_id' => $user->getKey(),
                    'subscription_id' => $subscription?->getKey(),
                    'operation' => $message->operation->value,
                    'idempotency_key' => $idempotencyKey,
                    'state' => OutboundUsageReservationState::Reserved->value,
                    'message_units' => 1,
                    'recipient_units' => $recipientUnits,
                    'attachment_bytes' => $attachmentBytes,
                    'reserved_at' => now(),
                    'expires_at' => now()->addSeconds((int) config('outbound_usage.reservation_ttl_seconds', 3600)),
                    'metadata' => ['usage_ids' => $usageIds],
                ]);
            } catch (QueryException $exception) {
                $existing = OutboundUsageReservation::query()
                    ->where('outbound_message_id', $message->getKey())
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                throw $exception;
            }
        });
    }

    /**
     * Read-only allowance pre-check without acquiring locks. Best-effort;
     * the authoritative check happens inside {@see reserve()}.
     */
    public function assertWithinAllowance(User $user, int $recipientUnits = 1, int $attachmentBytes = 0): void
    {
        if (! $this->meteringEnabled()) {
            return;
        }

        $subscription = $this->entitlements->currentSubscription($user);
        $this->checkAllowance($user, $subscription, max(0, $recipientUnits), max(0, $attachmentBytes), lock: false);
    }

    /**
     * Commit a reservation on provider acceptance (message state -> sent).
     * Idempotent: a duplicate commit (duplicate provider event / duplicate
     * job execution) is a no-op once the reservation is no longer
     * `reserved`.
     */
    public function commit(string $outboundMessageId): void
    {
        if (! $this->meteringEnabled()) {
            return;
        }

        DB::transaction(function () use ($outboundMessageId): void {
            $reservation = OutboundUsageReservation::query()
                ->where('outbound_message_id', $outboundMessageId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->state !== OutboundUsageReservationState::Reserved) {
                return;
            }

            $usageIds = (array) ($reservation->metadata['usage_ids'] ?? []);
            $unitsByDimension = [
                'messages' => $reservation->message_units,
                'recipients' => $reservation->recipient_units,
                'attachment_bytes' => $reservation->attachment_bytes,
            ];

            foreach ($unitsByDimension as $dimension => $units) {
                $usageId = $usageIds[$dimension] ?? null;
                if ($usageId === null || $units <= 0) {
                    continue;
                }

                $usage = SubscriptionUsage::query()->whereKey($usageId)->lockForUpdate()->first();
                if ($usage === null) {
                    continue;
                }

                $usage->forceFill([
                    'used_value' => $usage->used_value + $units,
                    'last_used_at' => now(),
                ])->save();
            }

            $reservation->forceFill([
                'state' => OutboundUsageReservationState::Committed->value,
                'committed_at' => now(),
            ])->save();
        });
    }

    /**
     * Release a reservation WITHOUT ever incrementing used_value. Only
     * valid for pre-transport-attempt outcomes; see class docblock for the
     * full release policy. Idempotent and safe to call on a reservation
     * that does not exist (metering disabled at reserve time) or is
     * already terminal.
     */
    public function release(string $outboundMessageId, string $reason, ?string $actorUserId = null): void
    {
        if (! $this->meteringEnabled()) {
            return;
        }

        $released = DB::transaction(function () use ($outboundMessageId, $reason): bool {
            $reservation = OutboundUsageReservation::query()
                ->where('outbound_message_id', $outboundMessageId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null || $reservation->state !== OutboundUsageReservationState::Reserved) {
                return false;
            }

            $reservation->forceFill([
                'state' => OutboundUsageReservationState::Released->value,
                'released_at' => now(),
                'release_reason' => $reason,
            ])->save();

            return true;
        });

        if ($released) {
            $this->audit->write(
                'outbound.usage_reservation_released',
                $actorUserId,
                null,
                null,
                ['state' => OutboundUsageReservationState::Released->value],
                ['outbound_message_id' => $outboundMessageId, 'reason' => $reason],
            );
        }
    }

    /**
     * Record that a delivery attempt was claimed for this message. Called
     * once per {@see DeliverOutboundMessageJob} claim (first
     * attempt and every retry). Increments `metadata.attempts` always and
     * `metadata.retries` when this is not the first attempt. No-op when
     * metering is disabled or the message was never reserved.
     */
    public function recordAttemptStarted(OutboundMessage $message): void
    {
        if (! $this->meteringEnabled()) {
            return;
        }

        DB::transaction(function () use ($message): void {
            $reservation = OutboundUsageReservation::query()
                ->where('outbound_message_id', $message->getKey())
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return;
            }

            $metadata = $reservation->metadata ?? [];
            $metadata['attempts'] = (int) ($metadata['attempts'] ?? 0) + 1;

            if ($message->attempt_count > 1) {
                $metadata['retries'] = (int) ($metadata['retries'] ?? 0) + 1;
            }

            $reservation->forceFill(['metadata' => $metadata])->save();
        });
    }

    /**
     * Record a terminal permanent failure (post transport-attempt). Does
     * NOT release the reservation — the message quota remains spent.
     */
    public function recordPermanentFailure(string $outboundMessageId): void
    {
        if (! $this->meteringEnabled()) {
            return;
        }

        DB::transaction(function () use ($outboundMessageId): void {
            $reservation = OutboundUsageReservation::query()
                ->where('outbound_message_id', $outboundMessageId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return;
            }

            $metadata = $reservation->metadata ?? [];
            $metadata['permanent_failures'] = (int) ($metadata['permanent_failures'] ?? 0) + 1;

            $reservation->forceFill(['metadata' => $metadata])->save();
        });
    }

    /**
     * Bounded, safety-net reconciliation for abandoned `reserved` rows
     * past their TTL. Only releases rows whose outbound message is in a
     * terminal state that the normal release policy would have covered
     * (cancelled, or failed with no transport attempt) — anything else is
     * left untouched and reported as ambiguous for manual review.
     *
     * @return array{scanned: int, released: int, ambiguous: int}
     */
    public function expireReservations(int $batchSize = 100, bool $dryRun = true): array
    {
        $report = ['scanned' => 0, 'released' => 0, 'ambiguous' => 0];

        $candidates = OutboundUsageReservation::query()
            ->where('state', OutboundUsageReservationState::Reserved->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('outboundMessage')
            ->orderBy('expires_at')
            ->limit(max(1, $batchSize))
            ->get();

        foreach ($candidates as $reservation) {
            $report['scanned']++;
            $message = $reservation->outboundMessage;

            $safeToRelease = $message !== null && (
                $message->state->value === 'cancelled'
                || ($message->state->value === 'failed' && $message->transport_attempted_at === null)
            );

            if (! $safeToRelease) {
                $report['ambiguous']++;

                continue;
            }

            if ($dryRun) {
                $report['released']++;

                continue;
            }

            DB::transaction(function () use ($reservation): void {
                $locked = OutboundUsageReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->first();
                if ($locked === null || $locked->state !== OutboundUsageReservationState::Reserved) {
                    return;
                }

                $locked->forceFill([
                    'state' => OutboundUsageReservationState::Expired->value,
                    'released_at' => now(),
                    'release_reason' => 'ttl_expired_safety_net',
                ])->save();
            });

            $report['released']++;
        }

        return $report;
    }

    /**
     * Safe, user-visible usage summary. No abuse thresholds are ever
     * included.
     *
     * @return array<string, mixed>
     */
    public function summaryForUser(User $user): array
    {
        $subscription = $this->entitlements->currentSubscription($user);
        $dimensions = $this->resolveDimensions($user, $subscription);

        $messages = $this->dimensionSummary($subscription, $dimensions['messages']);
        $recipients = $this->dimensionSummary($subscription, $dimensions['recipients']);
        $attachmentBytes = $this->dimensionSummary($subscription, $dimensions['attachment_bytes']);

        return [
            'messages_used' => $messages['used'],
            'messages_remaining' => $messages['remaining'],
            'messages_unlimited' => $messages['unlimited'],
            'recipients_used' => $recipients['used'],
            'recipients_remaining' => $recipients['remaining'],
            'recipients_unlimited' => $recipients['unlimited'],
            'attachment_bytes_used' => $attachmentBytes['used'],
            'attachment_bytes_remaining' => $attachmentBytes['remaining'],
            'attachment_bytes_unlimited' => $attachmentBytes['unlimited'],
            'reset_at' => $messages['reset_at'] ?? $recipients['reset_at'] ?? $attachmentBytes['reset_at'],
            'entitlements' => [
                'send_email' => $this->entitlements->hasFeature($user, 'send_email'),
                'reply_email' => $this->entitlements->hasFeature($user, 'reply_email'),
                'forward_email' => $this->entitlements->hasFeature($user, 'forward_email'),
            ],
        ];
    }

    /**
     * Platform-admin-only usage summary for an arbitrary user.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException When the actor is not a platform admin.
     */
    public function adminSummaryForUser(User $actor, User $target): array
    {
        if (! $actor->isPlatformAdmin()) {
            throw new AuthorizationException('Only platform admins may view another user\'s outbound usage.');
        }

        return $this->summaryForUser($target);
    }

    /**
     * Platform-admin-only manual usage correction. Reason-coded and
     * audited (`outbound.usage_corrected`); never used for payment or
     * invoicing purposes.
     *
     * @throws AuthorizationException When the actor is not a platform admin.
     */
    public function correctUsage(User $actor, User $target, string $dimensionKey, int $newUsedValue, string $reasonCode): SubscriptionUsage
    {
        if (! $actor->isPlatformAdmin()) {
            throw new AuthorizationException('Only platform admins may correct outbound usage.');
        }

        $featureKey = (string) config('outbound_usage.feature_keys.'.$dimensionKey);
        if ($featureKey === '') {
            throw new \InvalidArgumentException('Unknown outbound usage dimension: '.$dimensionKey);
        }

        $subscription = $this->entitlements->currentSubscription($target);
        if ($subscription === null) {
            throw new \RuntimeException('The target user has no active subscription to correct usage for.');
        }

        $feature = $this->entitlements->getFeature($target, $featureKey);
        if ($feature === null) {
            throw new \RuntimeException('The target user\'s plan does not meter this dimension.');
        }

        return DB::transaction(function () use ($actor, $target, $subscription, $feature, $newUsedValue, $reasonCode): SubscriptionUsage {
            $usage = SubscriptionUsage::query()
                ->where('subscription_id', $subscription->getKey())
                ->where('feature_id', $feature->getKey())
                ->where('period_end', '>=', now())
                ->lockForUpdate()
                ->first();

            if ($usage === null) {
                throw new \RuntimeException('No active usage period found to correct.');
            }

            $before = $usage->used_value;
            $usage->forceFill(['used_value' => max(0, $newUsedValue)])->save();

            $this->audit->write(
                'outbound.usage_corrected',
                (string) $actor->getKey(),
                $usage,
                ['used_value' => $before],
                ['used_value' => $usage->used_value],
                [
                    'target_user_id' => (string) $target->getKey(),
                    'feature_key' => $feature->key,
                    'reason_code' => $reasonCode,
                ],
            );

            return $usage->fresh();
        });
    }

    /**
     * @return array{used: int, remaining: int|null, unlimited: bool, reset_at: string|null}
     */
    private function dimensionSummary(?Subscription $subscription, array $dimension): array
    {
        if ($dimension['limit'] === null || $subscription === null) {
            return ['used' => 0, 'remaining' => null, 'unlimited' => true, 'reset_at' => null];
        }

        $usage = SubscriptionUsage::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('feature_id', $dimension['feature']->getKey())
            ->where('period_end', '>=', now())
            ->first();

        $used = (int) ($usage->used_value ?? 0);
        $limit = $dimension['limit'];

        return [
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'unlimited' => false,
            'reset_at' => $usage !== null ? $usage->period_end->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, array{feature: Feature|null, limit: int|null, resetPeriod: ResetPeriod}>
     */
    private function resolveDimensions(User $user, ?Subscription $subscription): array
    {
        $featureKeys = (array) config('outbound_usage.feature_keys', []);
        $defaultResetPeriod = ResetPeriod::tryFrom((string) config('outbound_usage.default_reset_period', 'monthly')) ?? ResetPeriod::Monthly;

        $result = [];

        foreach ($featureKeys as $dimension => $featureKey) {
            $feature = $this->entitlements->getFeature($user, (string) $featureKey);

            if ($feature === null) {
                $result[$dimension] = ['feature' => null, 'limit' => null, 'resetPeriod' => $defaultResetPeriod];

                continue;
            }

            $value = $this->entitlements->featureValue($user, (string) $featureKey);

            if (! is_array($value) || ! array_key_exists('limit', $value) || $value['limit'] === null || ! is_numeric($value['limit'])) {
                $result[$dimension] = ['feature' => null, 'limit' => null, 'resetPeriod' => $defaultResetPeriod];

                continue;
            }

            $resetPeriod = ResetPeriod::tryFrom((string) ($value['reset_period'] ?? $defaultResetPeriod->value)) ?? $defaultResetPeriod;

            $result[$dimension] = [
                'feature' => $feature,
                'limit' => max(0, (int) $value['limit']),
                'resetPeriod' => $resetPeriod,
            ];
        }

        return $result;
    }

    /**
     * Locks (when $lock) the relevant SubscriptionUsage rows, verifies
     * `used_value + outstanding reserved units + new units <= limit`, and
     * returns the locked rows keyed by dimension (limited dimensions
     * only). Throws {@see OutboundSendException} on the first exceeded
     * dimension.
     *
     * @return array<string, SubscriptionUsage>
     */
    private function checkAllowance(User $user, ?Subscription $subscription, int $recipientUnits, int $attachmentBytes, bool $lock): array
    {
        $dimensions = $this->resolveDimensions($user, $subscription);
        $usageRows = [];

        foreach ($dimensions as $dimension => $config) {
            if ($config['limit'] === null || $subscription === null) {
                continue;
            }

            $units = $this->unitsForDimension($dimension, $recipientUnits, $attachmentBytes);

            $query = SubscriptionUsage::query()
                ->where('subscription_id', $subscription->getKey())
                ->where('feature_id', $config['feature']->getKey())
                ->where('period_end', '>=', now());

            $usage = $lock ? $query->lockForUpdate()->first() : $query->first();

            if ($usage === null) {
                if (! $lock) {
                    [$periodStart] = $this->periodBounds($config['resetPeriod'], now());
                    $outstanding = $this->outstandingReservedUnits($subscription, $dimension, $periodStart);

                    if (($outstanding + $units) > $config['limit']) {
                        throw $this->quotaException($dimension);
                    }

                    continue;
                }

                $usage = $this->createUsageRow($subscription, $config['feature'], $config['limit'], $config['resetPeriod']);
            } elseif ($usage->limit_value !== $config['limit']) {
                // Keep the persisted limit in sync with the current plan
                // entitlement (e.g. after a plan change), never silently
                // widening an in-flight check.
                $usage->forceFill(['limit_value' => $config['limit']])->save();
            }

            $outstanding = $this->outstandingReservedUnits($subscription, $dimension, $usage->period_start);

            if (($usage->used_value + $outstanding + $units) > $usage->limit_value) {
                throw $this->quotaException($dimension);
            }

            $usageRows[$dimension] = $usage;
        }

        return $usageRows;
    }

    private function createUsageRow(Subscription $subscription, Feature $feature, int $limit, ResetPeriod $resetPeriod): SubscriptionUsage
    {
        [$start, $end] = $this->periodBounds($resetPeriod, now());

        return SubscriptionUsage::query()->create([
            'subscription_id' => $subscription->getKey(),
            'feature_id' => $feature->getKey(),
            'used_value' => 0,
            'limit_value' => $limit,
            'reset_period' => $resetPeriod->value,
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    private function outstandingReservedUnits(Subscription $subscription, string $dimension, Carbon $periodStart): int
    {
        $column = match ($dimension) {
            'messages' => 'message_units',
            'recipients' => 'recipient_units',
            'attachment_bytes' => 'attachment_bytes',
            default => null,
        };

        if ($column === null) {
            return 0;
        }

        return (int) OutboundUsageReservation::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('state', OutboundUsageReservationState::Reserved->value)
            ->where('reserved_at', '>=', $periodStart)
            ->sum($column);
    }

    private function unitsForDimension(string $dimension, int $recipientUnits, int $attachmentBytes): int
    {
        return match ($dimension) {
            'messages' => 1,
            'recipients' => $recipientUnits,
            'attachment_bytes' => $attachmentBytes,
            default => 0,
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodBounds(ResetPeriod $period, \DateTimeInterface $anchor): array
    {
        $now = $anchor instanceof Carbon ? $anchor->copy() : Carbon::instance(Carbon::parse($anchor));

        return match ($period) {
            ResetPeriod::Daily => [$now->copy()->startOfDay(), $now->copy()->startOfDay()->addDay()],
            ResetPeriod::Weekly => [$now->copy()->startOfWeek(), $now->copy()->startOfWeek()->addWeek()],
            ResetPeriod::Monthly => [$now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonthNoOverflow()],
            ResetPeriod::Yearly => [$now->copy()->startOfYear(), $now->copy()->startOfYear()->addYear()],
        };
    }

    private function quotaException(string $dimension): OutboundSendException
    {
        return new OutboundSendException(
            'outbound_quota_'.$dimension.'_exceeded',
            'The outbound '.str_replace('_', ' ', $dimension).' allowance has been exceeded for this billing period.',
            429,
        );
    }
}
