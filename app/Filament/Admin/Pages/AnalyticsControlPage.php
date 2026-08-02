<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Analytics\AnalyticsAggregationService;
use App\Services\Analytics\AnalyticsHealthCheckService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

final class AnalyticsControlPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Control';

    protected static ?string $title = 'Analytics Control';

    protected string $view = 'filament.admin.pages.analytics-control';

    /** @var array<string, mixed> */
    public array $health = [];

    /** @var array<string, mixed> */
    public array $settings = [];

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
        $this->health = app(AnalyticsHealthCheckService::class)->check();
        $this->settings = $this->safeSettings();
    }

    public function runBackfill(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            return;
        }

        try {
            $results = app(AnalyticsAggregationService::class)->rollupBackfill();
            $this->refreshState();
            Notification::make()
                ->title('Analytics backfill completed')
                ->body(count($results).' day(s) rolled up')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title('Backfill failed')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return array<string, mixed> */
    private function safeSettings(): array
    {
        return [
            'enabled' => (bool) config('analytics.enabled'),
            'scheduler' => (array) config('analytics.scheduler'),
            'retention' => (array) config('analytics.retention'),
            'rollup' => (array) config('analytics.rollup'),
            'queue' => (string) config('analytics.queue'),
        ];
    }
}
