<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Identity\SessionManagementService;
use App\Services\Settings\BillingPreferenceService;
use App\Services\Settings\NotificationPreferenceService;
use App\Services\Settings\SettingsApiKeyService;
use App\Services\Settings\SettingsHealthCheckService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class UserSettingsOverviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Identity';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'User Settings Overview';

    protected static ?string $title = 'User Settings Overview';

    protected string $view = 'filament.admin.pages.user-settings-overview';

    /** @var array<string, mixed> */
    public array $health = [];

    /** @var list<array<string, mixed>> */
    public array $recentUsers = [];

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
        $this->health = app(SettingsHealthCheckService::class)->check();

        $this->recentUsers = User::query()
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(function (User $user): array {
                $sessions = app(SessionManagementService::class)->listForUser($user);
                $keys = app(SettingsApiKeyService::class)->listForUser($user);
                $prefs = app(NotificationPreferenceService::class)->listForUser($user);
                $billing = app(BillingPreferenceService::class)->summary($user);

                return [
                    'id' => $user->getKey(),
                    'email' => $user->email,
                    'status' => $user->status->value,
                    'verified' => $user->email_verified_at !== null,
                    'sessions' => count($sessions),
                    'api_keys' => count(array_filter($keys, static fn (array $key): bool => ($key['active'] ?? false) === true)),
                    'notification_prefs' => count($prefs),
                    'billing_email' => $billing['billing_email'],
                    'closure' => $user->closed_at?->toIso8601String(),
                    'latest_export_status' => $user->privacyExports()->latest('requested_at')->value('status'),
                ];
            })
            ->all();
    }
}
