<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Enums\AnalyticsReportPeriod;
use App\Models\AnalyticsDailyRollup;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Report builder over daily rollups (read model only).
 */
final class AnalyticsReportService
{
    /**
     * @return array{
     *     period: string,
     *     from: string,
     *     to: string,
     *     rows: list<array{date: string, domain: string, metric_key: string, value: float}>
     * }
     */
    public function report(
        AnalyticsReportPeriod $period,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?AnalyticsDomain $domain = null,
    ): array {
        [$fromDate, $toDate] = $this->resolveRange($period, $from, $to);

        $query = AnalyticsDailyRollup::query()
            ->where('scope_key', 'platform')
            ->whereDate('bucket_date', '>=', $fromDate->toDateString())
            ->whereDate('bucket_date', '<=', $toDate->toDateString())
            ->orderBy('bucket_date')
            ->orderBy('domain')
            ->orderBy('metric_key');

        if ($domain !== null) {
            $query->where('domain', $domain->value);
        }

        /** @var Collection<int, AnalyticsDailyRollup> $rollups */
        $rollups = $query->get();

        if ($period === AnalyticsReportPeriod::Weekly || $period === AnalyticsReportPeriod::Monthly) {
            $rows = $this->collapse($rollups, $period);
        } else {
            $rows = $rollups->map(fn (AnalyticsDailyRollup $r): array => [
                'date' => $r->bucket_date->toDateString(),
                'domain' => $r->domain instanceof AnalyticsDomain ? $r->domain->value : (string) $r->domain,
                'metric_key' => (string) $r->metric_key,
                'value' => (float) $r->value,
            ])->values()->all();
        }

        return [
            'period' => $period->value,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<string> CSV lines including header
     */
    public function toCsv(array $report): array
    {
        $lines = ['date,domain,metric_key,value'];
        foreach ($report['rows'] as $row) {
            $lines[] = sprintf(
                '%s,%s,%s,%s',
                $row['date'],
                $row['domain'],
                $row['metric_key'],
                rtrim(rtrim(number_format((float) $row['value'], 4, '.', ''), '0'), '.') ?: '0',
            );
        }

        return $lines;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(AnalyticsReportPeriod $period, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $toDate = Carbon::parse(($to ?? now()->subDay())->toDateString())->endOfDay();

        $fromDate = match ($period) {
            AnalyticsReportPeriod::Daily => $from
                ? Carbon::parse($from->toDateString())->startOfDay()
                : $toDate->copy()->startOfDay(),
            AnalyticsReportPeriod::Weekly => $from
                ? Carbon::parse($from->toDateString())->startOfDay()
                : $toDate->copy()->subDays(6)->startOfDay(),
            AnalyticsReportPeriod::Monthly => $from
                ? Carbon::parse($from->toDateString())->startOfDay()
                : $toDate->copy()->subDays(29)->startOfDay(),
            AnalyticsReportPeriod::Custom => Carbon::parse(($from ?? $toDate->copy()->subDays(29))->toDateString())->startOfDay(),
        };

        return [$fromDate, $toDate];
    }

    /**
     * @param  Collection<int, AnalyticsDailyRollup>  $rollups
     * @return list<array{date: string, domain: string, metric_key: string, value: float}>
     */
    private function collapse(Collection $rollups, AnalyticsReportPeriod $period): array
    {
        $pointInTime = [
            AnalyticsMetricKey::UsersActive->value,
            AnalyticsMetricKey::UsersPremium->value,
            AnalyticsMetricKey::UsersFree->value,
            AnalyticsMetricKey::UsersRetentionBps->value,
            AnalyticsMetricKey::InboxActive->value,
            AnalyticsMetricKey::BillingMrrMinor->value,
            AnalyticsMetricKey::BillingArrMinor->value,
            AnalyticsMetricKey::AdsCtrBps->value,
        ];

        $buckets = [];
        foreach ($rollups as $row) {
            $domain = $row->domain instanceof AnalyticsDomain ? $row->domain->value : (string) $row->domain;
            $metric = (string) $row->metric_key;
            $label = $period === AnalyticsReportPeriod::Weekly
                ? $row->bucket_date->copy()->startOfWeek()->toDateString()
                : $row->bucket_date->copy()->startOfMonth()->toDateString();
            $key = $label.'|'.$domain.'|'.$metric;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'date' => $label,
                    'domain' => $domain,
                    'metric_key' => $metric,
                    'value' => 0.0,
                    '_latest_date' => null,
                ];
            }
            if (in_array($metric, $pointInTime, true)) {
                if ($buckets[$key]['_latest_date'] === null || $row->bucket_date->toDateString() >= $buckets[$key]['_latest_date']) {
                    $buckets[$key]['value'] = (float) $row->value;
                    $buckets[$key]['_latest_date'] = $row->bucket_date->toDateString();
                }
            } else {
                $buckets[$key]['value'] += (float) $row->value;
            }
        }

        return array_values(array_map(static function (array $row): array {
            unset($row['_latest_date']);

            return $row;
        }, $buckets));
    }
}
