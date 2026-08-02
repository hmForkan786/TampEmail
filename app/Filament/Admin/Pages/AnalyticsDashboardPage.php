<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Analytics\AnalyticsDashboardService;
use App\Services\Analytics\AnalyticsTrendService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class AnalyticsDashboardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Analytics Dashboard';

    protected string $view = 'filament.admin.pages.analytics-dashboard';

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $trends = [];

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
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $this->summary = app(AnalyticsDashboardService::class)->summary();
        $this->trends = app(AnalyticsTrendService::class)->series();
    }
}
