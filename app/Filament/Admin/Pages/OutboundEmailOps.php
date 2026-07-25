<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Outbound\OutboundOpsService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class OutboundEmailOps extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Outbound Email';

    protected string $view = 'filament.admin.pages.outbound-email-ops';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return 'Outbound Email';
    }

    protected function getViewData(): array
    {
        try {
            return $this->safeReport(app(OutboundOpsService::class)->report());
        } catch (\Throwable) {
            return [
                'status' => 'failed',
                'readiness' => ['state' => 'failed', 'transport' => 'unknown', 'configuration_valid' => false, 'failure_code' => 'ops_unavailable'],
                'volume' => ['last_24_hours' => [], 'last_7_days' => []],
                'retries' => [],
                'provider' => [],
                'issues' => ['ops_unavailable'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function safeReport(array $report): array
    {
        $status = $report['status'] ?? 'failed';
        if (! in_array($status, ['healthy', 'degraded', 'failed', 'unknown'], true)) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'evaluated_at' => is_string($report['evaluated_at'] ?? null) ? $report['evaluated_at'] : null,
            'readiness' => is_array($report['readiness'] ?? null) ? $report['readiness'] : [],
            'queue' => is_array($report['queue'] ?? null) ? $report['queue'] : [],
            'volume' => is_array($report['volume'] ?? null) ? $report['volume'] : [],
            'retries' => is_array($report['retries'] ?? null) ? $report['retries'] : [],
            'provider' => is_array($report['provider'] ?? null) ? $report['provider'] : [],
            'suppressions' => is_array($report['suppressions'] ?? null) ? $report['suppressions'] : [],
            'abuse' => is_array($report['abuse'] ?? null) ? $report['abuse'] : [],
            'issues' => array_values(array_filter(
                is_array($report['issues'] ?? null) ? $report['issues'] : [],
                fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1,
            )),
        ];
    }
}
