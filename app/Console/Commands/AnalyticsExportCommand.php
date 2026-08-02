<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsReportPeriod;
use App\Services\Analytics\AnalyticsReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

final class AnalyticsExportCommand extends Command
{
    protected $signature = 'analytics:export
                            {--period=daily : daily|weekly|monthly|custom}
                            {--from= : Start date Y-m-d}
                            {--to= : End date Y-m-d}
                            {--domain= : Optional domain filter (users|inbox|email|billing|affiliate|ads|api)}
                            {--path= : Relative storage path under local disk}';

    protected $description = 'Export analytics rollups as CSV (no PII)';

    public function handle(AnalyticsReportService $reports): int
    {
        $period = AnalyticsReportPeriod::tryFrom((string) $this->option('period')) ?? AnalyticsReportPeriod::Daily;
        $domainOpt = $this->option('domain');
        $domain = is_string($domainOpt) && $domainOpt !== ''
            ? AnalyticsDomain::tryFrom($domainOpt)
            : null;

        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : null;
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : null;

        $report = $reports->report($period, $from, $to, $domain);
        $csv = implode("\n", $reports->toCsv($report))."\n";

        $path = (string) ($this->option('path') ?: ('analytics/exports/analytics_'.$period->value.'_'.now()->format('Ymd_His').'.csv'));
        Storage::disk('local')->put($path, $csv);

        $this->line(json_encode([
            'path' => $path,
            'rows' => count($report['rows']),
            'from' => $report['from'],
            'to' => $report['to'],
            'period' => $report['period'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
