<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\PrivacyExportStatus;
use App\Models\UserPrivacyExport;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Billing\Invoice\BillingHistoryService;
use App\Services\Commercial\CommercialUsageSummaryService;
use App\Services\Identity\SessionManagementService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SettingsHealthCheckService
{
    public function __construct(
        private readonly NotificationPreferenceService $notifications,
        private readonly SessionManagementService $sessions,
        private readonly ApiKeyService $apiKeys,
        private readonly CommercialUsageSummaryService $usage,
        private readonly BillingHistoryService $billingHistory,
    ) {}

    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, metrics: array<string, mixed>}
     */
    public function check(): array
    {
        $checks = [];

        $requiredRoutes = [
            'settings.index',
            'settings.profile',
            'settings.security',
            'settings.sessions',
            'settings.notifications',
            'settings.api-keys',
            'settings.billing',
            'settings.privacy',
            'settings.account',
        ];

        $missingRoutes = array_values(array_filter($requiredRoutes, static fn (string $name): bool => ! Route::has($name)));
        $checks[] = [
            'name' => 'settings_routes',
            'ok' => $missingRoutes === [],
            'detail' => $missingRoutes === []
                ? 'All canonical settings routes registered.'
                : 'Missing routes: '.implode(', ', $missingRoutes),
        ];

        $registry = $this->notifications->registryHealth();
        $checks[] = [
            'name' => 'notification_preference_registry',
            'ok' => $registry['ok'],
            'detail' => $registry['detail'],
        ];

        $checks[] = [
            'name' => 'session_driver_compatibility',
            'ok' => true,
            'detail' => $this->sessions->supportsEnumeration()
                ? 'Session enumeration supported.'
                : 'Session driver does not support enumeration; UI fails closed.',
        ];

        $checks[] = [
            'name' => 'api_key_service',
            'ok' => true,
            'detail' => 'ApiKeyService resolved ('.$this->apiKeys::class.').',
        ];

        $checks[] = [
            'name' => 'billing_summary_service',
            'ok' => true,
            'detail' => 'BillingHistoryService ('.$this->billingHistory::class.') and CommercialUsageSummaryService ('.$this->usage::class.') resolved.',
        ];

        $disk = (string) config('settings.privacy.export.disk', 'local');
        $exportOk = true;
        $exportDetail = 'Privacy export storage reachable.';
        try {
            Storage::disk($disk)->exists((string) config('settings.privacy.export.directory', 'private/settings/exports'));
        } catch (Throwable) {
            $exportOk = false;
            $exportDetail = 'Privacy export storage unavailable.';
        }
        $checks[] = [
            'name' => 'privacy_export_storage',
            'ok' => $exportOk,
            'detail' => $exportDetail,
        ];

        $staleExports = 0;
        $failedExports = 0;
        try {
            $staleExports = UserPrivacyExport::query()
                ->where('status', PrivacyExportStatus::Ready->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->count();

            $failedExports = UserPrivacyExport::query()
                ->where('status', PrivacyExportStatus::Failed->value)
                ->where('updated_at', '>=', now()->subDay())
                ->count();
        } catch (Throwable) {
            $checks[] = [
                'name' => 'privacy_export_metrics',
                'ok' => false,
                'detail' => 'Unable to query privacy export metrics (database unavailable).',
            ];
        }

        $checks[] = [
            'name' => 'stale_export_requests',
            'ok' => true,
            'detail' => $staleExports.' expired ready export(s) pending prune.',
        ];

        $checks[] = [
            'name' => 'failed_settings_exports',
            'ok' => $failedExports === 0,
            'detail' => $failedExports === 0
                ? 'No failed exports in the last 24 hours.'
                : $failedExports.' failed export(s) in the last 24 hours.',
        ];

        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return [
            'ok' => $ok,
            'checks' => $checks,
            'metrics' => [
                'stale_exports' => $staleExports,
                'failed_exports_24h' => $failedExports,
                'export_enabled' => (bool) config('settings.privacy.export.enabled', true),
                'avatar_enabled' => (bool) config('settings.avatar.enabled', false),
            ],
        ];
    }
}
