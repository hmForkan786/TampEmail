<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateProfile;

/**
 * Projects an affiliate's ledger balances from the append-only
 * {@see AffiliateCommissionEntry} rows.
 *
 * Accounting model (append-only, amounts are immutable; only `status`
 * transitions on hold/maturity lifecycle rows):
 *  - commission:          +amount, status pending -> available
 *  - reversal:             -amount, status mirrors the entry it reverses
 *  - withdrawal_hold:      -amount, status held (reserves funds for a request)
 *  - withdrawal_release:   +amount, status available (on reject/cancel)
 *  - payout:               -amount, status paid (on successful payout)
 *
 * `net_available` (the actually withdrawable amount) is the sum of
 * `available` entries minus anything currently `held` for other pending
 * withdrawal requests, so an affiliate cannot double-spend the same funds
 * across multiple simultaneous withdrawal requests.
 */
final class AffiliateBalanceService
{
    /**
     * @return array{pending: int, available: int, held: int, paid: int, reversed: int, net_available: int}
     */
    public function project(AffiliateProfile $profile, string $currency): array
    {
        $currency = strtoupper($currency);

        $base = fn () => AffiliateCommissionEntry::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->where('currency', $currency);

        $pending = (int) $base()
            ->where('status', AffiliateCommissionEntryStatus::Pending->value)
            ->where('amount_minor', '>', 0)
            ->sum('amount_minor');

        $available = (int) $base()
            ->where('status', AffiliateCommissionEntryStatus::Available->value)
            ->sum('amount_minor');

        $held = abs((int) $base()
            ->where('status', AffiliateCommissionEntryStatus::Held->value)
            ->where('entry_type', AffiliateCommissionEntryType::WithdrawalHold->value)
            ->sum('amount_minor'));

        $paid = abs((int) $base()
            ->where('entry_type', AffiliateCommissionEntryType::Payout->value)
            ->where('status', AffiliateCommissionEntryStatus::Paid->value)
            ->sum('amount_minor'));

        $reversed = abs((int) $base()
            ->where('entry_type', AffiliateCommissionEntryType::Reversal->value)
            ->sum('amount_minor'));

        return [
            'pending' => $pending,
            'available' => $available,
            'held' => $held,
            'paid' => $paid,
            'reversed' => $reversed,
            'net_available' => max(0, $available - $held),
        ];
    }
}
