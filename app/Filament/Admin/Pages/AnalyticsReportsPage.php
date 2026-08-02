<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsReportPeriod;
use App\Models\User;
use App\Services\Analytics\AnalyticsReportService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

final class AnalyticsReportsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Analytics Reports';

    protected string $view = 'filament.admin.pages.analytics-reports';

    public string $period = 'daily';

    public ?string $from = null;

    public ?string $to = null;

    public ?string $domain = null;

    /** @var array<string, mixed> */
    public array $report = [];

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        $this->to = now()->subDay()->toDateString();
        $this->from = now()->subDays(7)->toDateString();
        $this->refreshReport();
    }

    public function refreshReport(): void
    {
        $period = AnalyticsReportPeriod::tryFrom($this->period) ?? AnalyticsReportPeriod::Daily;
        $domain = $this->domain ? AnalyticsDomain::tryFrom($this->domain) : null;

        $this->report = app(AnalyticsReportService::class)->report(
            $period,
            $this->from ? Carbon::parse($this->from) : null,
            $this->to ? Carbon::parse($this->to) : null,
            $domain,
        );
    }

    public function exportCsv(): StreamedResponse
    {
        $this->refreshReport();
        $lines = app(AnalyticsReportService::class)->toCsv($this->report);
        $filename = 'analytics_'.$this->period.'_'.now()->format('Ymd_His').'.csv';

        Notification::make()->title('CSV export ready')->success()->send();

        return response()->streamDownload(function () use ($lines): void {
            echo implode("\n", $lines)."\n";
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return AnalyticsReportPeriod::labels();
    }

    /** @return array<string, string> */
    public function domainOptions(): array
    {
        return ['' => 'All domains'] + AnalyticsDomain::labels();
    }
}
