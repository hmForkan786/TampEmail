<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Ads\AdEmergencyStopService;
use App\Services\Ads\AdHealthCheckService;
use App\Services\Ads\AdStatisticsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class AdsControlPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Ads';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Ads Control';

    protected string $view = 'filament.admin.pages.ads-control';

    /** @var array<string, mixed> */
    public array $health = [];

    /** @var array<string, mixed> */
    public array $stats = [];

    public bool $emergency_stop = false;

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
        $this->health = app(AdHealthCheckService::class)->check();
        $this->stats = app(AdStatisticsService::class)->summary();
        $this->emergency_stop = app(AdEmergencyStopService::class)->isStopped();
    }

    public function engageEmergencyStop(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            return;
        }

        app(AdEmergencyStopService::class)->engage($actor);
        $this->refreshState();
        Notification::make()->title('Ads emergency stop engaged')->danger()->send();
    }

    public function releaseEmergencyStop(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            return;
        }

        app(AdEmergencyStopService::class)->release($actor);
        $this->refreshState();
        Notification::make()->title('Ads emergency stop released')->success()->send();
    }
}
