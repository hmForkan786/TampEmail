<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Models\AnalyticsDailyRollup;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Admin analytics dashboard read model (platform scope).
 */
final class AnalyticsDashboardService
{
    public function __construct(
        private readonly AnalyticsMetricCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     as_of: string,
     *     range: array{from: string, to: string},
     *     domains: array<string, array<string, float|int>>,
     *     totals: array<string, float|int>
     * }
     */
    public function summary(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $to = Carbon::parse(($to ?? now()->subDay())->toDateString())->endOfDay();
        $from = Carbon::parse(($from ?? $to->copy()->subDays(29))->toDateString())->startOfDay();

        $rollups = AnalyticsDailyRollup::query()
            ->where('scope_key', 'platform')
            ->whereDate('bucket_date', '>=', $from->toDateString())
            ->whereDate('bucket_date', '<=', $to->toDateString())
            ->get();

        $domains = [];
        foreach (AnalyticsDomain::cases() as $domain) {
            $domains[$domain->value] = [];
            foreach ($this->catalog->forDomain($domain) as $metric) {
                $domains[$domain->value][$metric->value] = 0;
            }
        }

        foreach ($rollups as $row) {
            $domain = $row->domain instanceof AnalyticsDomain ? $row->domain->value : (string) $row->domain;
            $key = (string) $row->metric_key;
            if (! isset($domains[$domain])) {
                $domains[$domain] = [];
            }
            $domains[$domain][$key] = ($domains[$domain][$key] ?? 0) + (float) $row->value;
        }

        // Point-in-time metrics: use latest bucket value (not sum).
        foreach ([
            [AnalyticsDomain::Users, AnalyticsMetricKey::UsersActive],
            [AnalyticsDomain::Users, AnalyticsMetricKey::UsersPremium],
            [AnalyticsDomain::Users, AnalyticsMetricKey::UsersFree],
            [AnalyticsDomain::Users, AnalyticsMetricKey::UsersRetentionBps],
            [AnalyticsDomain::Inbox, AnalyticsMetricKey::InboxActive],
            [AnalyticsDomain::Billing, AnalyticsMetricKey::BillingMrrMinor],
            [AnalyticsDomain::Billing, AnalyticsMetricKey::BillingArrMinor],
            [AnalyticsDomain::Ads, AnalyticsMetricKey::AdsCtrBps],
        ] as [$domain, $metric]) {
            $latest = $rollups
                ->filter(fn (AnalyticsDailyRollup $r): bool => ($r->domain instanceof AnalyticsDomain ? $r->domain : AnalyticsDomain::tryFrom((string) $r->domain)) === $domain
                    && (string) $r->metric_key === $metric->value)
                ->sortByDesc(fn (AnalyticsDailyRollup $r): string => $r->bucket_date->toDateString())
                ->first();
            $domains[$domain->value][$metric->value] = $latest ? (float) $latest->value : 0;
        }

        return [
            'as_of' => now()->toIso8601String(),
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'domains' => $domains,
            'totals' => [
                'revenue_minor' => (float) ($domains[AnalyticsDomain::Billing->value][AnalyticsMetricKey::BillingRevenueMinor->value] ?? 0),
                'users_registered' => (float) ($domains[AnalyticsDomain::Users->value][AnalyticsMetricKey::UsersRegistrations->value] ?? 0),
                'mail_received' => (float) ($domains[AnalyticsDomain::Email->value][AnalyticsMetricKey::EmailReceived->value] ?? 0),
                'ads_impressions' => (float) ($domains[AnalyticsDomain::Ads->value][AnalyticsMetricKey::AdsImpressions->value] ?? 0),
                'api_requests' => (float) ($domains[AnalyticsDomain::Api->value][AnalyticsMetricKey::ApiRequests->value] ?? 0),
            ],
        ];
    }
}
