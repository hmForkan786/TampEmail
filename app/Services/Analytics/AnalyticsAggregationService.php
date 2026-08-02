<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AffiliateAttributionStatus;
use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateWithdrawalStatus;
use App\Enums\AnalyticsAggregationStatus;
use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Enums\BillingOrderStatus;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\SubscriptionStatus;
use App\Events\Analytics\AnalyticsRollupCompleted;
use App\Events\Analytics\AnalyticsRollupFailed;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Models\AdRevenueEntry;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateConversion;
use App\Models\AffiliateWithdrawal;
use App\Models\AnalyticsAggregationRun;
use App\Models\AnalyticsDailyRollup;
use App\Models\AnalyticsEvent;
use App\Models\ApiRequestLog;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BillingOrder;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Scheduled aggregation from existing subsystem tables into daily rollups.
 * Read-only against source modules — never mutates Billing/Mail/API state.
 */
final class AnalyticsAggregationService
{
    public function __construct(
        private readonly AnalyticsEventCollector $collector,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('analytics.enabled', true);
    }

    /**
     * Roll up a calendar day (UTC date boundaries of the app timezone).
     *
     * @return array{run: AnalyticsAggregationRun, metrics_written: int}
     */
    public function rollupDay(CarbonInterface $day): array
    {
        $bucket = Carbon::parse($day->toDateString())->startOfDay();

        if (! $this->enabled()) {
            $run = AnalyticsAggregationRun::query()->create([
                'bucket_date' => $bucket->toDateString(),
                'status' => AnalyticsAggregationStatus::Succeeded->value,
                'metrics_written' => 0,
                'events_ingested' => 0,
                'started_at' => now(),
                'finished_at' => now(),
                'meta' => ['skipped' => true, 'reason' => 'analytics_disabled'],
            ]);

            return ['run' => $run, 'metrics_written' => 0];
        }

        $run = AnalyticsAggregationRun::query()->create([
            'bucket_date' => $bucket->toDateString(),
            'status' => AnalyticsAggregationStatus::Running->value,
            'metrics_written' => 0,
            'events_ingested' => 0,
            'started_at' => now(),
        ]);

        try {
            $metrics = $this->computePlatformMetrics($bucket);
            $written = 0;

            DB::transaction(function () use ($bucket, $metrics, &$written): void {
                foreach ($metrics as $row) {
                    AnalyticsDailyRollup::query()->updateOrCreate(
                        [
                            'bucket_date' => $bucket->toDateString(),
                            'domain' => $row['domain']->value,
                            'metric_key' => $row['metric']->value,
                            'scope_key' => 'platform',
                        ],
                        [
                            'value' => $row['value'],
                            'owner_id' => null,
                            'meta' => $row['meta'] ?? null,
                        ],
                    );
                    $written++;
                }
            });

            $eventsIngested = AnalyticsEvent::query()
                ->whereBetween('occurred_at', [$bucket->copy()->startOfDay(), $bucket->copy()->endOfDay()])
                ->count();

            $run->forceFill([
                'status' => AnalyticsAggregationStatus::Succeeded->value,
                'metrics_written' => $written,
                'events_ingested' => $eventsIngested,
                'finished_at' => now(),
                'error_message' => null,
            ])->save();

            event(new AnalyticsRollupCompleted($run->fresh()));

            return ['run' => $run->fresh(), 'metrics_written' => $written];
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => AnalyticsAggregationStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            event(new AnalyticsRollupFailed($run->fresh(), $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Backfill missing successful days within the configured window.
     *
     * @return list<array{date: string, metrics_written: int}>
     */
    public function rollupBackfill(?int $days = null): array
    {
        $days ??= (int) config('analytics.rollup.backfill_days', 7);
        $results = [];
        $cursor = now()->subDay()->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->copy()->subDays($i);
            $hasSuccess = AnalyticsAggregationRun::query()
                ->whereDate('bucket_date', $date->toDateString())
                ->where('status', AnalyticsAggregationStatus::Succeeded->value)
                ->exists();

            if ($hasSuccess) {
                continue;
            }

            $result = $this->rollupDay($date);
            $results[] = [
                'date' => $date->toDateString(),
                'metrics_written' => $result['metrics_written'],
            ];
        }

        return $results;
    }

    /**
     * @return list<array{domain: AnalyticsDomain, metric: AnalyticsMetricKey, value: float|int, meta?: array<string, mixed>}>
     */
    private function computePlatformMetrics(Carbon $bucket): array
    {
        $start = $bucket->copy()->startOfDay();
        $end = $bucket->copy()->endOfDay();

        $rows = [];

        // —— Users ——
        $rows[] = $this->metric(AnalyticsDomain::Users, AnalyticsMetricKey::UsersRegistrations, User::query()->whereBetween('created_at', [$start, $end])->count());

        $activeDays = (int) config('analytics.rollup.active_user_days', 30);
        $activeSince = $end->copy()->subDays($activeDays);
        // Proxy: users with activity via updated_at or ownership of recent emails/inboxes/subscriptions.
        $activeUsers = User::query()
            ->where(function ($q) use ($activeSince): void {
                $q->where('updated_at', '>=', $activeSince)
                    ->orWhereHas('subscription', fn ($s) => $s->whereIn('status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::Trial->value,
                        SubscriptionStatus::Grace->value,
                        SubscriptionStatus::RenewalDue->value,
                    ]));
            })
            ->count();
        $rows[] = $this->metric(AnalyticsDomain::Users, AnalyticsMetricKey::UsersActive, $activeUsers);

        $premiumPlanIds = Plan::query()->where('is_free', false)->pluck('id');
        $freePlanIds = Plan::query()->where('is_free', true)->pluck('id');
        $premium = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value, SubscriptionStatus::Grace->value, SubscriptionStatus::RenewalDue->value])
            ->whereIn('plan_id', $premiumPlanIds)
            ->count();
        $free = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value, SubscriptionStatus::Grace->value, SubscriptionStatus::RenewalDue->value])
            ->whereIn('plan_id', $freePlanIds)
            ->count();
        $rows[] = $this->metric(AnalyticsDomain::Users, AnalyticsMetricKey::UsersPremium, $premium);
        $rows[] = $this->metric(AnalyticsDomain::Users, AnalyticsMetricKey::UsersFree, $free);

        $cohortDays = (int) config('analytics.rollup.retention_cohort_days', 30);
        $cohortStart = $end->copy()->subDays($cohortDays)->startOfDay();
        $cohortEnd = $end->copy()->subDays($cohortDays)->endOfDay();
        $cohortSize = User::query()->whereBetween('created_at', [$cohortStart, $cohortEnd])->count();
        $retained = 0;
        if ($cohortSize > 0) {
            $retained = User::query()
                ->whereBetween('created_at', [$cohortStart, $cohortEnd])
                ->where('updated_at', '>=', $end->copy()->subDays(7))
                ->count();
        }
        $retentionBps = $cohortSize > 0 ? (int) round(($retained / $cohortSize) * 10000) : 0;
        $rows[] = $this->metric(AnalyticsDomain::Users, AnalyticsMetricKey::UsersRetentionBps, $retentionBps, [
            'cohort_size' => $cohortSize,
            'retained' => $retained,
            'cohort_days' => $cohortDays,
        ]);

        // —— Inbox ——
        $rows[] = $this->metric(AnalyticsDomain::Inbox, AnalyticsMetricKey::InboxCreated, Inbox::query()->whereBetween('created_at', [$start, $end])->count());
        $rows[] = $this->metric(AnalyticsDomain::Inbox, AnalyticsMetricKey::InboxActive, Inbox::query()->where('is_active', true)->where(function ($q) use ($end): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', $end);
        })->count());
        $rows[] = $this->metric(AnalyticsDomain::Inbox, AnalyticsMetricKey::InboxExpired, Inbox::query()->whereBetween('expires_at', [$start, $end])->count());
        $rows[] = $this->metric(AnalyticsDomain::Inbox, AnalyticsMetricKey::InboxRenewed, AuditLog::query()
            ->where('action', 'inbox.expiration_extended')
            ->whereBetween('created_at', [$start, $end])
            ->count());

        // —— Email ——
        $rows[] = $this->metric(AnalyticsDomain::Email, AnalyticsMetricKey::EmailReceived, Email::query()->whereBetween('created_at', [$start, $end])->count());
        $sentStates = [OutboundMessageState::Sent->value, OutboundMessageState::Delivered->value];
        $rows[] = $this->metric(AnalyticsDomain::Email, AnalyticsMetricKey::EmailSent, OutboundMessage::query()
            ->where('operation', OutboundOperation::Send->value)
            ->whereIn('state', $sentStates)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('sent_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end): void {
                        $q2->whereNull('sent_at')->whereBetween('delivered_at', [$start, $end]);
                    });
            })
            ->count());
        $rows[] = $this->metric(AnalyticsDomain::Email, AnalyticsMetricKey::EmailReply, OutboundMessage::query()
            ->where('operation', OutboundOperation::Reply->value)
            ->whereIn('state', $sentStates)
            ->whereBetween('sent_at', [$start, $end])
            ->count());
        $rows[] = $this->metric(AnalyticsDomain::Email, AnalyticsMetricKey::EmailForward, OutboundMessage::query()
            ->where('operation', OutboundOperation::Forward->value)
            ->whereIn('state', $sentStates)
            ->whereBetween('sent_at', [$start, $end])
            ->count());
        $rows[] = $this->metric(AnalyticsDomain::Email, AnalyticsMetricKey::EmailAttachments, Attachment::query()->whereBetween('created_at', [$start, $end])->count());

        // —— Billing ——
        $orders = BillingOrder::query()->whereBetween('created_at', [$start, $end]);
        $rows[] = $this->metric(AnalyticsDomain::Billing, AnalyticsMetricKey::BillingOrders, (clone $orders)->count());
        $paidQuery = BillingOrder::query()->where('status', BillingOrderStatus::Paid->value)->whereBetween('paid_at', [$start, $end]);
        $rows[] = $this->metric(AnalyticsDomain::Billing, AnalyticsMetricKey::BillingPaid, (clone $paidQuery)->count());
        $revenue = (int) (clone $paidQuery)->sum('total_minor');
        $rows[] = $this->metric(AnalyticsDomain::Billing, AnalyticsMetricKey::BillingRevenueMinor, $revenue);
        $rows[] = $this->metric(AnalyticsDomain::Billing, AnalyticsMetricKey::BillingFailed, BillingOrder::query()
            ->where('status', BillingOrderStatus::Failed->value)
            ->whereBetween('updated_at', [$start, $end])
            ->count());

        // price_monthly / subscription.price are major currency units → minor (×100).
        $mrrMajor = (float) Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Grace->value, SubscriptionStatus::RenewalDue->value])
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.is_free', false)
            ->sum(DB::raw('COALESCE(subscriptions.price, plans.price_monthly, 0)'));
        $mrr = (int) round($mrrMajor * 100);
        $rows[] = $this->metric(AnalyticsDomain::Billing, AnalyticsMetricKey::BillingMrrMinor, $mrr);
        $rows[] = $this->metric(AnalyticsDomain::Billing, AnalyticsMetricKey::BillingArrMinor, $mrr * 12);

        // —— Affiliate ——
        $rows[] = $this->metric(AnalyticsDomain::Affiliate, AnalyticsMetricKey::AffiliateClicks, AffiliateAttribution::query()
            ->whereBetween('first_seen_at', [$start, $end])
            ->count());
        $rows[] = $this->metric(AnalyticsDomain::Affiliate, AnalyticsMetricKey::AffiliateSignups, AffiliateAttribution::query()
            ->where('status', AffiliateAttributionStatus::Converted->value)
            ->whereBetween('converted_at', [$start, $end])
            ->count());
        $rows[] = $this->metric(AnalyticsDomain::Affiliate, AnalyticsMetricKey::AffiliateConversions, AffiliateConversion::query()
            ->whereBetween('created_at', [$start, $end])
            ->count());
        $rows[] = $this->metric(AnalyticsDomain::Affiliate, AnalyticsMetricKey::AffiliateCommissionMinor, (int) AffiliateCommissionEntry::query()
            ->where('entry_type', AffiliateCommissionEntryType::Commission->value)
            ->whereIn('status', [
                AffiliateCommissionEntryStatus::Pending->value,
                AffiliateCommissionEntryStatus::Available->value,
                AffiliateCommissionEntryStatus::Paid->value,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount_minor'));
        $rows[] = $this->metric(AnalyticsDomain::Affiliate, AnalyticsMetricKey::AffiliateWithdrawals, AffiliateWithdrawal::query()
            ->where('status', AffiliateWithdrawalStatus::Paid->value)
            ->whereBetween('paid_at', [$start, $end])
            ->count());

        // —— Ads ——
        $impressions = AdImpression::query()->whereBetween('created_at', [$start, $end])->count();
        $clicks = AdClick::query()->whereBetween('created_at', [$start, $end])->count();
        $ctrBps = $impressions > 0 ? (int) round(($clicks / $impressions) * 10000) : 0;
        $adsRevenue = (int) AdRevenueEntry::query()->whereBetween('earned_on', [$start->toDateString(), $end->toDateString()])->sum('amount_minor');
        $rows[] = $this->metric(AnalyticsDomain::Ads, AnalyticsMetricKey::AdsImpressions, $impressions);
        $rows[] = $this->metric(AnalyticsDomain::Ads, AnalyticsMetricKey::AdsClicks, $clicks);
        $rows[] = $this->metric(AnalyticsDomain::Ads, AnalyticsMetricKey::AdsCtrBps, $ctrBps);
        $rows[] = $this->metric(AnalyticsDomain::Ads, AnalyticsMetricKey::AdsRevenueMinor, $adsRevenue);

        // —— API ——
        $apiBase = ApiRequestLog::query()->whereBetween('created_at', [$start, $end]);
        $rows[] = $this->metric(AnalyticsDomain::Api, AnalyticsMetricKey::ApiRequests, (clone $apiBase)->count());
        $rows[] = $this->metric(AnalyticsDomain::Api, AnalyticsMetricKey::ApiErrors, (clone $apiBase)->where('response_status', '>=', 500)->count());
        $rows[] = $this->metric(AnalyticsDomain::Api, AnalyticsMetricKey::ApiRateLimited, (clone $apiBase)->where('response_status', 429)->count());
        $rows[] = $this->metric(AnalyticsDomain::Api, AnalyticsMetricKey::ApiKeyUsage, (clone $apiBase)->whereNotNull('api_key_id')->distinct('api_key_id')->count('api_key_id'));

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array{domain: AnalyticsDomain, metric: AnalyticsMetricKey, value: float|int, meta?: array<string, mixed>}
     */
    private function metric(AnalyticsDomain $domain, AnalyticsMetricKey $metric, float|int $value, ?array $meta = null): array
    {
        $row = [
            'domain' => $domain,
            'metric' => $metric,
            'value' => $value,
        ];
        if ($meta !== null) {
            $row['meta'] = $this->collector->sanitizeDimensions($meta);
        }

        return $row;
    }
}
