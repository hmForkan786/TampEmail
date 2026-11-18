<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateConversionStatus;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Promotes held commission entries to `available` once their hold period
 * has elapsed, skipping any entry whose conversion was rejected/reversed.
 */
final class AffiliateCommissionMaturityService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly AffiliateNotificationService $notifications,
    ) {}

    /**
     * @return array{matured: int, skipped: int}
     */
    public function mature(int $limit = 200, bool $dryRun = false): array
    {
        $matured = 0;
        $skipped = 0;

        $entries = AffiliateCommissionEntry::query()
            ->where('entry_type', AffiliateCommissionEntryType::Commission->value)
            ->where('status', AffiliateCommissionEntryStatus::Pending->value)
            ->where('available_at', '<=', now())
            ->limit($limit)
            ->get();

        foreach ($entries as $entry) {
            DB::transaction(function () use ($entry, $dryRun, &$matured, &$skipped): void {
                $locked = AffiliateCommissionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->first();

                if (! $locked instanceof AffiliateCommissionEntry || $locked->status !== AffiliateCommissionEntryStatus::Pending) {
                    $skipped++;

                    return;
                }

                $conversion = $locked->conversion_id !== null
                    ? AffiliateConversion::query()->whereKey($locked->conversion_id)->first()
                    : null;

                if ($conversion instanceof AffiliateConversion
                    && in_array($conversion->status, [AffiliateConversionStatus::Rejected, AffiliateConversionStatus::Reversed], true)
                ) {
                    $skipped++;

                    return;
                }

                if ($dryRun) {
                    $matured++;

                    return;
                }

                $locked->forceFill(['status' => AffiliateCommissionEntryStatus::Available])->save();

                $this->audit->write('affiliate.commission_matured', null, $locked, null, [
                    'affiliate_profile_id' => $locked->affiliate_profile_id,
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                ]);

                $affiliate = AffiliateProfile::query()->find($locked->affiliate_profile_id);

                if ($affiliate instanceof AffiliateProfile && $affiliate->user instanceof User) {
                    $this->notifications->notify($affiliate->user, 'affiliate.commission_available', [
                        'amount_minor' => $locked->amount_minor,
                        'currency' => $locked->currency,
                    ], 'commission-available:'.$locked->getKey());
                }

                $matured++;
            });
        }

        return ['matured' => $matured, 'skipped' => $skipped];
    }
}
