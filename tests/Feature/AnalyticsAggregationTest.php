<?php

declare(strict_types=1);

use App\Enums\AnalyticsAggregationStatus;
use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Enums\AnalyticsReportPeriod;
use App\Models\AnalyticsDailyRollup;
use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Services\Analytics\AnalyticsAggregationService;
use App\Services\Analytics\AnalyticsDashboardService;
use App\Services\Analytics\AnalyticsEventCollector;
use App\Services\Analytics\AnalyticsHealthCheckService;
use App\Services\Analytics\AnalyticsReportService;
use App\Services\Analytics\AnalyticsTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'analytics.enabled' => true,
        'analytics.scheduler.rollup_enabled' => true,
        'analytics.rollup.backfill_days' => 3,
    ]);
});

it('records sanitized analytics events and strips PII dimensions', function (): void {
    $user = User::factory()->create();

    $event = app(AnalyticsEventCollector::class)->record(
        AnalyticsDomain::Ads,
        AnalyticsMetricKey::AdsImpressions,
        1,
        now(),
        (string) $user->getKey(),
        'test',
        [
            'campaign_id' => (string) Str::uuid(),
            'email' => 'secret@example.com',
            'ip_address' => '1.2.3.4',
            'subject' => 'private',
        ],
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class)
        ->and($event->dimensions)->toHaveKey('campaign_id')
        ->and($event->dimensions)->not->toHaveKey('email')
        ->and($event->dimensions)->not->toHaveKey('ip_address')
        ->and($event->dimensions)->not->toHaveKey('subject');
});

it('runs analytics rollup and builds platform dashboard metrics', function (): void {
    $user = User::factory()->create(['created_at' => now()->subDay()]);

    $exit = Artisan::call('analytics:rollup', [
        '--date' => now()->subDay()->toDateString(),
    ]);

    expect($exit)->toBe(0);

    $registrations = AnalyticsDailyRollup::query()
        ->where('scope_key', 'platform')
        ->where('domain', AnalyticsDomain::Users->value)
        ->where('metric_key', AnalyticsMetricKey::UsersRegistrations->value)
        ->whereDate('bucket_date', now()->subDay()->toDateString())
        ->value('value');

    expect((float) $registrations)->toBeGreaterThanOrEqual(1.0);

    $summary = app(AnalyticsDashboardService::class)->summary(
        now()->subDays(7),
        now()->subDay(),
    );

    expect($summary)->toHaveKeys(['as_of', 'range', 'domains', 'totals'])
        ->and($summary['domains'])->toHaveKey('users')
        ->and($summary['domains'])->toHaveKey('billing')
        ->and($summary['domains'])->toHaveKey('ads');

    $trends = app(AnalyticsTrendService::class)->series(now()->subDays(7), now()->subDay());
    expect($trends['labels'])->not->toBeEmpty()
        ->and($trends['series'])->toHaveKey('users_registrations');

    $report = app(AnalyticsReportService::class)->report(
        AnalyticsReportPeriod::Daily,
        now()->subDays(7),
        now()->subDay(),
    );
    expect($report['rows'])->not->toBeEmpty();

    $csv = app(AnalyticsReportService::class)->toCsv($report);
    expect($csv[0])->toBe('date,domain,metric_key,value')
        ->and(implode("\n", $csv))->not->toContain('@');

    expect($user->fresh())->not->toBeNull();
});

it('reports analytics health and lists artisan commands', function (): void {
    $health = app(AnalyticsHealthCheckService::class)->check();
    expect($health)->toHaveKey('healthy')
        ->and($health)->toHaveKey('backlog_days');

    $exit = Artisan::call('analytics:health');
    expect($exit)->toBeIn([0, 1])
        ->and(Artisan::output())->toContain('healthy');

    Artisan::call('list');
    $output = Artisan::output();
    expect($output)->toContain('analytics:rollup')
        ->and($output)->toContain('analytics:health')
        ->and($output)->toContain('analytics:prune')
        ->and($output)->toContain('analytics:export');
});

it('exports csv via artisan', function (): void {
    app(AnalyticsAggregationService::class)->rollupDay(now()->subDay());

    $exit = Artisan::call('analytics:export', [
        '--period' => 'daily',
        '--from' => now()->subDay()->toDateString(),
        '--to' => now()->subDay()->toDateString(),
        '--path' => 'analytics/exports/test_export.csv',
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('test_export.csv');
});

it('marks successful aggregation runs', function (): void {
    $result = app(AnalyticsAggregationService::class)->rollupDay(now()->subDay());

    expect($result['run']->status)->toBe(AnalyticsAggregationStatus::Succeeded)
        ->and($result['metrics_written'])->toBeGreaterThan(0);
});
