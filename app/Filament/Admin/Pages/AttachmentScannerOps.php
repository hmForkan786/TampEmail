<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Inbound\AttachmentScannerLiveCheckService;
use App\Services\Inbound\AttachmentScannerOpsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use UnitEnum;

final class AttachmentScannerOps extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Attachment Scanner';

    protected string $view = 'filament.admin.pages.attachment-scanner-ops';

    /** @var array<string, mixed>|null */
    public ?array $liveCheckResult = null;

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
        return 'Attachment Scanner';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('liveCheck')
                ->label('Run live check')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Run scanner live check')
                ->modalDescription('Probes the scanner with in-memory clean and infected test content. Results are sanitized and rate-limited.')
                ->visible(fn (): bool => self::canAccess())
                ->action(function (): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
                        Notification::make()->title('Unauthorized')->danger()->send();

                        return;
                    }

                    $key = 'attachments-scanner-live-check:'.$actor->getKey();
                    $maxAttempts = max(1, (int) config('attachments.ops.live_check_per_minute', 1));
                    if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                        Notification::make()->title('Live check rate limited')->warning()->send();

                        return;
                    }

                    RateLimiter::hit($key, 60);

                    try {
                        $result = app(AttachmentScannerLiveCheckService::class)->check();
                        $this->liveCheckResult = [
                            'status' => $this->safeText($result['status'] ?? 'failed'),
                            'backend' => $this->safeText($result['backend'] ?? 'unknown'),
                            'clean_probe' => $this->safeText($result['clean_probe'] ?? 'unknown'),
                            'infected_probe' => $this->safeText($result['infected_probe'] ?? 'unknown'),
                            'issues' => array_values(array_filter(
                                is_array($result['issues'] ?? null) ? $result['issues'] : [],
                                fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1,
                            )),
                        ];
                        Notification::make()
                            ->title('Live check: '.$this->liveCheckResult['status'])
                            ->success()
                            ->send();
                    } catch (\Throwable) {
                        $this->liveCheckResult = [
                            'status' => 'failed',
                            'backend' => 'unknown',
                            'clean_probe' => 'failed',
                            'infected_probe' => 'failed',
                            'issues' => ['live_check_unavailable'],
                        ];
                        Notification::make()->title('Live check failed')->danger()->send();
                    }
                }),
        ];
    }

    protected function getViewData(): array
    {
        try {
            $report = app(AttachmentScannerOpsService::class)->report();
            $safe = $this->safeReport(is_array($report) ? $report : []);
            $safe['live_check'] = $this->liveCheckResult;

            return $safe;
        } catch (\Throwable) {
            $unavailable = $this->unavailableReport();
            $unavailable['live_check'] = $this->liveCheckResult;

            return $unavailable;
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function safeReport(array $report): array
    {
        $status = $report['status'] ?? null;
        if (! in_array($status, ['healthy', 'degraded', 'failed'], true)) {
            return $this->unavailableReport();
        }

        $readiness = is_array($report['readiness'] ?? null) ? $report['readiness'] : [];
        $counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];
        $queue = is_array($report['queue'] ?? null) ? $report['queue'] : [];
        $quarantine = is_array($report['quarantine'] ?? null) ? $report['quarantine'] : [];

        return [
            'status' => $status,
            'evaluated_at' => now()->toIso8601String(),
            'readiness' => [
                'backend' => $this->safeText($readiness['backend'] ?? 'unknown'),
                'configuration_valid' => ($readiness['configuration_valid'] ?? false) === true,
                'daemon_reachable' => ($readiness['daemon_reachable'] ?? false) === true,
                'protocol_ready' => ($readiness['protocol_ready'] ?? false) === true,
                'last_successful_health_check_at' => $this->safeTimestamp($readiness['last_successful_health_check_at'] ?? null),
                'last_failed_health_check_at' => $this->safeTimestamp($readiness['last_failed_health_check_at'] ?? null),
                'failure_code' => $this->safeText($readiness['failure_code'] ?? 'none'),
                'state' => $this->safeText($readiness['state'] ?? 'unknown'),
            ],
            'counts' => [
                'last_24_hours' => $this->safeCounts($counts['last_24_hours'] ?? []),
                'last_7_days' => $this->safeCounts($counts['last_7_days'] ?? []),
            ],
            'queue' => [
                'queue_name' => $this->safeText($queue['queue_name'] ?? 'unknown'),
                'pending_scan_jobs' => $this->safeNumber($queue['pending_scan_jobs'] ?? null),
                'oldest_pending_scan_job_age_seconds' => $this->safeNumber($queue['oldest_pending_scan_job_age_seconds'] ?? null),
                'failed_scan_jobs' => $this->safeNumber($queue['failed_scan_jobs'] ?? null),
                'retry_backlog' => $this->safeNumber($queue['retry_backlog'] ?? null),
                'currently_processing' => $this->safeNumber($queue['currently_processing'] ?? null),
                'oldest_pending_attachment_age_seconds' => $this->safeNumber($queue['oldest_pending_attachment_age_seconds'] ?? null),
            ],
            'quarantine' => [
                'infected_count' => $this->safeNumber($quarantine['infected_count'] ?? null),
                'failed_count' => $this->safeNumber($quarantine['failed_count'] ?? null),
                'awaiting_review' => $this->safeNumber($quarantine['awaiting_review'] ?? null),
                'oldest_quarantined_age_seconds' => $this->safeNumber($quarantine['oldest_quarantined_age_seconds'] ?? null),
                'recent_permanent_deletions_24h' => $this->safeNumber($quarantine['recent_permanent_deletions_24h'] ?? null),
            ],
            'issues' => array_values(array_filter(
                is_array($report['issues'] ?? null) ? $report['issues'] : [],
                fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1,
            )),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function safeCounts(mixed $counts): array
    {
        $counts = is_array($counts) ? $counts : [];

        return [
            'pending' => $this->safeNumber($counts['pending'] ?? 0),
            'scanning' => $this->safeNumber($counts['scanning'] ?? 0),
            'clean' => $this->safeNumber($counts['clean'] ?? 0),
            'infected' => $this->safeNumber($counts['infected'] ?? 0),
            'failed' => $this->safeNumber($counts['failed'] ?? 0),
            'skipped' => $this->safeNumber($counts['skipped'] ?? 0),
            'retry_scheduled' => $this->safeNumber($counts['retry_scheduled'] ?? 0),
            'retry_exhausted' => $this->safeNumber($counts['retry_exhausted'] ?? 0),
            'permanently_deleted' => $this->safeNumber($counts['permanently_deleted'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableReport(): array
    {
        return [
            'status' => 'failed',
            'evaluated_at' => now()->toIso8601String(),
            'readiness' => [
                'backend' => 'unavailable',
                'configuration_valid' => false,
                'daemon_reachable' => false,
                'protocol_ready' => false,
                'last_successful_health_check_at' => null,
                'last_failed_health_check_at' => null,
                'failure_code' => 'health_unavailable',
                'state' => 'failed',
            ],
            'counts' => [
                'last_24_hours' => $this->safeCounts([]),
                'last_7_days' => $this->safeCounts([]),
            ],
            'queue' => [
                'queue_name' => 'unavailable',
                'pending_scan_jobs' => 'unknown',
                'oldest_pending_scan_job_age_seconds' => 'unknown',
                'failed_scan_jobs' => 'unknown',
                'retry_backlog' => 'unknown',
                'currently_processing' => 'unknown',
                'oldest_pending_attachment_age_seconds' => 'unknown',
            ],
            'quarantine' => [
                'infected_count' => 'unknown',
                'failed_count' => 'unknown',
                'awaiting_review' => 'unknown',
                'oldest_quarantined_age_seconds' => 'unknown',
                'recent_permanent_deletions_24h' => 'unknown',
            ],
            'issues' => ['health_unavailable'],
        ];
    }

    private function safeText(mixed $value): string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, 80) : 'unknown';
    }

    private function safeNumber(mixed $value): int|string
    {
        return is_numeric($value) ? max(0, min((int) $value, 2147483647)) : 'unknown';
    }

    private function safeTimestamp(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1
            ? mb_substr($value, 0, 40)
            : null;
    }
}
