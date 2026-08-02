<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Models\AnalyticsDailyRollup;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Trend series for charts (growth, revenue, mail volume, etc.).
 */
final class AnalyticsTrendService
{
    /**
     * @return array{
     *     labels: list<string>,
     *     series: array<string, list<float>>
     * }
     */
    public function series(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        int $days = 30,
    ): array {
        $to = Carbon::parse(($to ?? now()->subDay())->toDateString())->endOfDay();
        $from = Carbon::parse(($from ?? $to->copy()->subDays(max(1, $days) - 1))->toDateString())->startOfDay();

        $wanted = [
            'users_registrations' => [AnalyticsDomain::Users, AnalyticsMetricKey::UsersRegistrations],
            'billing_revenue_minor' => [AnalyticsDomain::Billing, AnalyticsMetricKey::BillingRevenueMinor],
            'inbox_created' => [AnalyticsDomain::Inbox, AnalyticsMetricKey::InboxCreated],
            'email_received' => [AnalyticsDomain::Email, AnalyticsMetricKey::EmailReceived],
            'affiliate_conversions' => [AnalyticsDomain::Affiliate, AnalyticsMetricKey::AffiliateConversions],
            'ads_revenue_minor' => [AnalyticsDomain::Ads, AnalyticsMetricKey::AdsRevenueMinor],
        ];

        $labels = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $series = [];
        foreach ($wanted as $name => [$domain, $metric]) {
            $series[$name] = array_fill(0, count($labels), 0.0);
        }

        $index = array_flip($labels);

        $rows = AnalyticsDailyRollup::query()
            ->where('scope_key', 'platform')
            ->whereDate('bucket_date', '>=', $from->toDateString())
            ->whereDate('bucket_date', '<=', $to->toDateString())
            ->where(function ($q) use ($wanted): void {
                foreach ($wanted as [$domain, $metric]) {
                    $q->orWhere(function ($inner) use ($domain, $metric): void {
                        $inner->where('domain', $domain->value)->where('metric_key', $metric->value);
                    });
                }
            })
            ->get();

        foreach ($rows as $row) {
            $date = $row->bucket_date->toDateString();
            if (! isset($index[$date])) {
                continue;
            }
            $domain = $row->domain instanceof AnalyticsDomain ? $row->domain->value : (string) $row->domain;
            $metric = (string) $row->metric_key;
            foreach ($wanted as $name => [$d, $m]) {
                if ($d->value === $domain && $m->value === $metric) {
                    $series[$name][$index[$date]] = (float) $row->value;
                }
            }
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }
}
