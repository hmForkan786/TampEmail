<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateConversionStatus;
use App\Exceptions\Affiliates\AffiliateException;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Reverses (fully or partially) a previously granted commission via an
 * append-only negative ledger entry. Original entries are never edited.
 */
final class AffiliateCommissionReversalService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly AffiliateNotificationService $notifications,
    ) {}

    public function reverseConversion(
        AffiliateConversion $conversion,
        string $reasonCode,
        ?int $partialAmountMinor = null,
        ?User $actor = null,
    ): AffiliateCommissionEntry {
        return DB::transaction(function () use ($conversion, $reasonCode, $partialAmountMinor, $actor): AffiliateCommissionEntry {
            $lockedConversion = AffiliateConversion::query()->whereKey($conversion->getKey())->lockForUpdate()->firstOrFail();

            $originalEntry = AffiliateCommissionEntry::query()
                ->where('conversion_id', $lockedConversion->getKey())
                ->where('entry_type', AffiliateCommissionEntryType::Commission->value)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $originalEntry instanceof AffiliateCommissionEntry) {
                throw new AffiliateException('No commission entry found for this conversion.');
            }

            $alreadyReversedMinor = abs((int) AffiliateCommissionEntry::query()
                ->where('conversion_id', $lockedConversion->getKey())
                ->where('entry_type', AffiliateCommissionEntryType::Reversal->value)
                ->sum('amount_minor'));

            if ($alreadyReversedMinor >= $originalEntry->amount_minor) {
                throw new AffiliateException('Commission for this conversion has already been fully reversed.');
            }

            $requested = $partialAmountMinor !== null ? max(0, $partialAmountMinor) : $originalEntry->amount_minor;
            $remaining = $originalEntry->amount_minor - $alreadyReversedMinor;
            $reverseAmount = min($requested, $remaining);

            if ($reverseAmount <= 0) {
                throw new AffiliateException('Nothing to reverse.');
            }

            $idempotencyKey = sprintf('reversal:%s:%s:%d', $lockedConversion->getKey(), $reasonCode, $reverseAmount);

            $existing = AffiliateCommissionEntry::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing instanceof AffiliateCommissionEntry) {
                return $existing;
            }

            $entry = AffiliateCommissionEntry::query()->create([
                'affiliate_profile_id' => $originalEntry->affiliate_profile_id,
                'conversion_id' => $lockedConversion->getKey(),
                'entry_type' => AffiliateCommissionEntryType::Reversal,
                'amount_minor' => -$reverseAmount,
                'currency' => $originalEntry->currency,
                'status' => $originalEntry->status === AffiliateCommissionEntryStatus::Pending
                    ? AffiliateCommissionEntryStatus::Pending
                    : AffiliateCommissionEntryStatus::Available,
                'reason_code' => $reasonCode,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor?->getKey(),
            ]);

            $isFullReversal = ($alreadyReversedMinor + $reverseAmount) >= $originalEntry->amount_minor;

            if ($isFullReversal) {
                $lockedConversion->forceFill([
                    'status' => AffiliateConversionStatus::Reversed,
                    'reversed_at' => now(),
                    'reason_code' => $reasonCode,
                ])->save();
            }

            $this->audit->write('affiliate.commission_reversed', $actor?->getKey(), $entry, null, [
                'conversion_id' => $lockedConversion->getKey(),
                'amount_minor' => $reverseAmount,
                'reason_code' => $reasonCode,
                'full_reversal' => $isFullReversal,
            ]);

            $affiliate = AffiliateProfile::query()->find($originalEntry->affiliate_profile_id);

            if ($affiliate instanceof AffiliateProfile && $affiliate->user instanceof User) {
                $this->notifications->notify($affiliate->user, 'affiliate.commission_reversed', [
                    'amount_minor' => $reverseAmount,
                    'currency' => $originalEntry->currency,
                ], 'commission-reversed:'.$entry->getKey());
            }

            return $entry;
        });
    }
}
