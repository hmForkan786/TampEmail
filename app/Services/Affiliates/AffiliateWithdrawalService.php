<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliatePayoutMethod;
use App\Enums\AffiliateWithdrawalStatus;
use App\Exceptions\Affiliates\AffiliateWithdrawalException;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Affiliate withdrawal request state machine.
 *
 * Permission checks (who is allowed to call which transition) are left to
 * callers; this service enforces business/data-integrity rules only:
 * valid state transitions, balance sufficiency, separation-of-duties, and
 * exactly-once ledger effects (hold / release / payout).
 */
final class AffiliateWithdrawalService
{
    public function __construct(
        private readonly AffiliateBalanceService $balances,
        private readonly AuditLogWriter $audit,
        private readonly AffiliateNotificationService $notifications,
    ) {}

    public function request(
        AffiliateProfile $profile,
        int $amountMinor,
        string $currency,
        string $payoutMethod,
        string $payoutDetails,
        string $idempotencyKey,
    ): AffiliateWithdrawal {
        $currency = strtoupper($currency);
        $method = AffiliatePayoutMethod::from($payoutMethod);

        if ($amountMinor <= 0) {
            throw new AffiliateWithdrawalException('Withdrawal amount must be positive.');
        }

        if ($amountMinor < (int) config('affiliates.min_withdrawal_minor', 0)) {
            throw new AffiliateWithdrawalException('Withdrawal amount is below the configured minimum.');
        }

        return DB::transaction(function () use ($profile, $amountMinor, $currency, $method, $payoutDetails, $idempotencyKey): AffiliateWithdrawal {
            $lockedProfile = AffiliateProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedProfile->canWithdraw()) {
                throw new AffiliateWithdrawalException('This affiliate account cannot request withdrawals.');
            }

            $existing = AffiliateWithdrawal::query()
                ->where('affiliate_profile_id', $lockedProfile->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof AffiliateWithdrawal) {
                return $existing;
            }

            $balance = $this->balances->project($lockedProfile, $currency);

            if ($balance['net_available'] < $amountMinor) {
                throw new AffiliateWithdrawalException('Insufficient available balance for this withdrawal.');
            }

            $withdrawal = AffiliateWithdrawal::query()->create([
                'affiliate_profile_id' => $lockedProfile->getKey(),
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => AffiliateWithdrawalStatus::Requested,
                'payout_method' => $method,
                'payout_details_snapshot_encrypted' => $payoutDetails,
                'idempotency_key' => $idempotencyKey,
                'requested_at' => now(),
            ]);

            AffiliateCommissionEntry::query()->create([
                'affiliate_profile_id' => $lockedProfile->getKey(),
                'withdrawal_id' => $withdrawal->getKey(),
                'entry_type' => AffiliateCommissionEntryType::WithdrawalHold,
                'amount_minor' => -$amountMinor,
                'currency' => $currency,
                'status' => AffiliateCommissionEntryStatus::Held,
                'idempotency_key' => 'withdrawal-hold:'.$withdrawal->getKey(),
            ]);

            $this->audit->write('affiliate.withdrawal_requested', $lockedProfile->user_id, $withdrawal, null, [
                'amount_minor' => $amountMinor,
                'currency' => $currency,
            ]);

            return $withdrawal;
        });
    }

    public function startReview(AffiliateWithdrawal $withdrawal, User $reviewer): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reviewer): AffiliateWithdrawal {
            $locked = $this->lock($withdrawal);
            $this->assertTransition($locked->status, [AffiliateWithdrawalStatus::Requested]);
            $this->assertSeparationOfDuties($locked, $reviewer);

            $locked->forceFill([
                'status' => AffiliateWithdrawalStatus::UnderReview,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->write('affiliate.withdrawal_review_started', $reviewer->getKey(), $locked);

            return $locked;
        });
    }

    public function approve(AffiliateWithdrawal $withdrawal, User $approver): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $approver): AffiliateWithdrawal {
            $locked = $this->lock($withdrawal);

            if ($locked->status === AffiliateWithdrawalStatus::Approved) {
                return $locked;
            }

            $this->assertTransition($locked->status, [
                AffiliateWithdrawalStatus::Requested, AffiliateWithdrawalStatus::UnderReview,
            ]);
            $this->assertSeparationOfDuties($locked, $approver);

            $locked->forceFill([
                'status' => AffiliateWithdrawalStatus::Approved,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->write('affiliate.withdrawal_approved', $approver->getKey(), $locked);

            $affiliate = AffiliateProfile::query()->find($locked->affiliate_profile_id);

            if ($affiliate instanceof AffiliateProfile && $affiliate->user instanceof User) {
                $this->notifications->notify($affiliate->user, 'affiliate.withdrawal_approved', [
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                ], 'withdrawal-approved:'.$locked->getKey());
            }

            return $locked;
        });
    }

    public function reject(AffiliateWithdrawal $withdrawal, User $admin, ?string $reason): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $admin, $reason): AffiliateWithdrawal {
            $locked = $this->lock($withdrawal);

            if ($locked->status === AffiliateWithdrawalStatus::Rejected) {
                return $locked;
            }

            $this->assertTransition($locked->status, [
                AffiliateWithdrawalStatus::Requested, AffiliateWithdrawalStatus::UnderReview, AffiliateWithdrawalStatus::Approved,
            ]);

            $this->releaseHold($locked, $admin);

            $locked->forceFill([
                'status' => AffiliateWithdrawalStatus::Rejected,
                'rejection_reason' => $this->sanitizeReason($reason),
            ])->save();

            $this->audit->write('affiliate.withdrawal_rejected', $admin->getKey(), $locked, null, ['reason' => $locked->rejection_reason]);

            $affiliate = AffiliateProfile::query()->find($locked->affiliate_profile_id);

            if ($affiliate instanceof AffiliateProfile && $affiliate->user instanceof User) {
                $this->notifications->notify($affiliate->user, 'affiliate.withdrawal_rejected', [
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                ], 'withdrawal-rejected:'.$locked->getKey());
            }

            return $locked;
        });
    }

    public function markProcessing(AffiliateWithdrawal $withdrawal, User $admin): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $admin): AffiliateWithdrawal {
            $locked = $this->lock($withdrawal);

            if ($locked->status === AffiliateWithdrawalStatus::Processing) {
                return $locked;
            }

            $this->assertTransition($locked->status, [AffiliateWithdrawalStatus::Approved]);

            $locked->forceFill(['status' => AffiliateWithdrawalStatus::Processing])->save();

            $this->audit->write('affiliate.withdrawal_processing', $admin->getKey(), $locked);

            return $locked;
        });
    }

    public function markPaid(AffiliateWithdrawal $withdrawal, User $admin, string $externalReference): AffiliateWithdrawal
    {
        $externalReference = trim($externalReference);

        if ($externalReference === '') {
            throw new AffiliateWithdrawalException('An external reference is required to mark a withdrawal as paid.');
        }

        return DB::transaction(function () use ($withdrawal, $admin, $externalReference): AffiliateWithdrawal {
            $locked = $this->lock($withdrawal);

            if ($locked->status === AffiliateWithdrawalStatus::Paid) {
                return $locked;
            }

            $this->assertTransition($locked->status, [
                AffiliateWithdrawalStatus::Approved, AffiliateWithdrawalStatus::Processing,
            ]);
            $this->assertSeparationOfDuties($locked, $admin);

            $hold = AffiliateCommissionEntry::query()
                ->where('withdrawal_id', $locked->getKey())
                ->where('entry_type', AffiliateCommissionEntryType::WithdrawalHold->value)
                ->lockForUpdate()
                ->first();

            if ($hold instanceof AffiliateCommissionEntry && $hold->status !== AffiliateCommissionEntryStatus::Paid) {
                $hold->forceFill(['status' => AffiliateCommissionEntryStatus::Paid])->save();
            }

            $payoutIdempotencyKey = 'payout:'.$locked->getKey();
            $existingPayout = AffiliateCommissionEntry::query()->where('idempotency_key', $payoutIdempotencyKey)->first();

            if (! $existingPayout instanceof AffiliateCommissionEntry) {
                AffiliateCommissionEntry::query()->create([
                    'affiliate_profile_id' => $locked->affiliate_profile_id,
                    'withdrawal_id' => $locked->getKey(),
                    'entry_type' => AffiliateCommissionEntryType::Payout,
                    'amount_minor' => -$locked->amount_minor,
                    'currency' => $locked->currency,
                    'status' => AffiliateCommissionEntryStatus::Paid,
                    'idempotency_key' => $payoutIdempotencyKey,
                    'created_by' => $admin->getKey(),
                ]);
            }

            $locked->forceFill([
                'status' => AffiliateWithdrawalStatus::Paid,
                'paid_by' => $admin->getKey(),
                'paid_at' => now(),
                'external_reference' => Str::limit($externalReference, 255, ''),
            ])->save();

            $this->audit->write('affiliate.withdrawal_paid', $admin->getKey(), $locked, null, [
                'external_reference' => $locked->external_reference,
            ]);

            $affiliate = AffiliateProfile::query()->find($locked->affiliate_profile_id);

            if ($affiliate instanceof AffiliateProfile && $affiliate->user instanceof User) {
                $this->notifications->notify($affiliate->user, 'affiliate.withdrawal_paid', [
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                ], 'withdrawal-paid:'.$locked->getKey());
            }

            return $locked;
        });
    }

    public function cancel(AffiliateWithdrawal $withdrawal, ?User $actor = null): AffiliateWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $actor): AffiliateWithdrawal {
            $locked = $this->lock($withdrawal);

            if ($locked->status === AffiliateWithdrawalStatus::Cancelled) {
                return $locked;
            }

            $this->assertTransition($locked->status, [
                AffiliateWithdrawalStatus::Requested, AffiliateWithdrawalStatus::UnderReview, AffiliateWithdrawalStatus::Approved,
            ]);

            $this->releaseHold($locked, $actor);

            $locked->forceFill(['status' => AffiliateWithdrawalStatus::Cancelled])->save();

            $this->audit->write('affiliate.withdrawal_cancelled', $actor?->getKey(), $locked);

            return $locked;
        });
    }

    private function lock(AffiliateWithdrawal $withdrawal): AffiliateWithdrawal
    {
        return AffiliateWithdrawal::query()->whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<AffiliateWithdrawalStatus>  $allowedFrom
     */
    private function assertTransition(AffiliateWithdrawalStatus $current, array $allowedFrom): void
    {
        if (! in_array($current, $allowedFrom, true)) {
            throw new AffiliateWithdrawalException(sprintf('Withdrawal cannot transition from status [%s].', $current->value));
        }
    }

    private function assertSeparationOfDuties(AffiliateWithdrawal $withdrawal, User $actor): void
    {
        if (config('affiliates.same_actor_payout_restriction') !== true) {
            return;
        }

        $affiliate = AffiliateProfile::query()->find($withdrawal->affiliate_profile_id);

        $priorActors = array_filter([
            $affiliate?->user_id,
            $withdrawal->reviewed_by,
            $withdrawal->approved_by,
        ]);

        if (in_array($actor->getKey(), $priorActors, true)) {
            throw new AffiliateWithdrawalException('Separation of duties: this account already acted on this withdrawal.');
        }
    }

    private function releaseHold(AffiliateWithdrawal $withdrawal, ?User $actor): void
    {
        $hold = AffiliateCommissionEntry::query()
            ->where('withdrawal_id', $withdrawal->getKey())
            ->where('entry_type', AffiliateCommissionEntryType::WithdrawalHold->value)
            ->where('status', AffiliateCommissionEntryStatus::Held->value)
            ->lockForUpdate()
            ->first();

        if (! $hold instanceof AffiliateCommissionEntry) {
            return;
        }

        $releaseKey = 'withdrawal-release:'.$withdrawal->getKey();
        $existingRelease = AffiliateCommissionEntry::query()->where('idempotency_key', $releaseKey)->first();

        if (! $existingRelease instanceof AffiliateCommissionEntry) {
            AffiliateCommissionEntry::query()->create([
                'affiliate_profile_id' => $withdrawal->affiliate_profile_id,
                'withdrawal_id' => $withdrawal->getKey(),
                'entry_type' => AffiliateCommissionEntryType::WithdrawalRelease,
                'amount_minor' => abs($hold->amount_minor),
                'currency' => $hold->currency,
                'status' => AffiliateCommissionEntryStatus::Available,
                'idempotency_key' => $releaseKey,
                'created_by' => $actor?->getKey(),
            ]);
        }

        $hold->forceFill(['status' => AffiliateCommissionEntryStatus::Cancelled])->save();
    }

    private function sanitizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $clean = trim(strip_tags($reason));

        return $clean === '' ? null : Str::limit($clean, 500, '');
    }
}
