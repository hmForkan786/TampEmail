<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateCommissionPlanStatus;
use App\Enums\AffiliateFraudDecision;
use App\Enums\AffiliateWithdrawalStatus;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateCommissionPlan;
use App\Models\AffiliateFraudFlag;
use App\Models\AffiliateWithdrawal;

/**
 * Point-in-time health snapshot for the affiliate subsystem.
 */
final class AffiliateHealthCheckService
{
    /**
     * @return array{healthy: bool, checks: array<string, array{status: string, value: mixed}>, evaluated_at: string}
     */
    public function check(): array
    {
        $enabled = config('affiliates.enabled') === true;

        $hasActivePlan = AffiliateCommissionPlan::query()
            ->where('status', AffiliateCommissionPlanStatus::Active->value)
            ->exists();

        $maturityBacklog = AffiliateCommissionEntry::query()
            ->where('entry_type', AffiliateCommissionEntryType::Commission->value)
            ->where('status', AffiliateCommissionEntryStatus::Pending->value)
            ->where('available_at', '<=', now())
            ->count();

        $staleWithdrawals = AffiliateWithdrawal::query()
            ->whereIn('status', [AffiliateWithdrawalStatus::Requested->value, AffiliateWithdrawalStatus::UnderReview->value])
            ->where('requested_at', '<=', now()->subDays(7))
            ->count();

        $pendingFraudReviews = AffiliateFraudFlag::query()
            ->where('decision', AffiliateFraudDecision::ManualReview->value)
            ->whereNull('reviewed_at')
            ->count();

        $checks = [
            'enabled' => ['status' => $enabled ? 'ok' : 'warn', 'value' => $enabled],
            'active_commission_plan' => ['status' => (! $enabled || $hasActivePlan) ? 'ok' : 'fail', 'value' => $hasActivePlan],
            'maturity_backlog' => ['status' => $maturityBacklog > 500 ? 'warn' : 'ok', 'value' => $maturityBacklog],
            'stale_withdrawals' => ['status' => $staleWithdrawals > 0 ? 'warn' : 'ok', 'value' => $staleWithdrawals],
            'pending_fraud_reviews' => ['status' => $pendingFraudReviews > 50 ? 'warn' : 'ok', 'value' => $pendingFraudReviews],
        ];

        $healthy = ! collect($checks)->contains(static fn (array $check): bool => $check['status'] === 'fail');

        return [
            'healthy' => $healthy,
            'checks' => $checks,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}
