<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Ops\ProcessReadinessService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use BackedEnum;
use UnitEnum;

final class ProcessHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;
    protected static string|UnitEnum|null $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 10;
    protected static ?string $title = 'Process Health';
    protected string $view = 'filament.admin.pages.process-health';

    public static function canAccess(): bool
    {
        $actor = auth()->user();
        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return 'Process Health';
    }

    protected function getViewData(): array
    {
        try {
            $report = app(ProcessReadinessService::class)->report();
            return $this->safeReport(is_array($report) ? $report : []);
        } catch (\Throwable) {
            return $this->unavailableReport();
        }
    }

    private function safeReport(array $report): array
    {
        $status = $report['status'] ?? null;
        if (! in_array($status, ['healthy', 'degraded', 'failed'], true)) {
            return $this->unavailableReport();
        }

        $queue = is_array($report['queue'] ?? null) ? $report['queue'] : [];
        $lock = is_array($report['lock_store'] ?? null) ? $report['lock_store'] : [];
        $worker = is_array($report['worker'] ?? null) ? $report['worker'] : [];
        $scheduler = is_array($report['scheduler'] ?? null) ? $report['scheduler'] : [];

        return [
            'status' => $status,
            'evaluated_at' => now()->toIso8601String(),
            'queue' => [
                'connection' => $this->safeText($queue['connection'] ?? 'unknown'),
                'backlog' => $this->safeNumber($queue['backlog'] ?? null),
                'oldest_job_age_seconds' => $this->safeNumber($queue['oldest_job_age_seconds'] ?? null),
                'failed_jobs' => $this->safeNumber($queue['failed_jobs'] ?? null),
            ],
            'lock_store' => [
                'cache' => $this->safeText($lock['cache'] ?? 'unknown'),
                'compatible' => ($lock['compatible'] ?? false) === true,
            ],
            'worker' => $this->safeWorkers($worker),
            'scheduler' => [
                'fresh' => ($scheduler['fresh'] ?? false) === true,
                'heartbeat_at' => $this->safeTimestamp($scheduler['record']['last_heartbeat_at'] ?? null),
                'status' => $this->safeText($scheduler['record']['status'] ?? 'unknown'),
            ],
            'issues' => array_values(array_filter(is_array($report['issues'] ?? null) ? $report['issues'] : [], fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1)),
        ];
    }

    private function safeWorkers(array $worker): array
    {
        $records = [];
        foreach (is_array($worker['records'] ?? null) ? $worker['records'] : [] as $record) {
            if (! is_array($record)) continue;
            $records[] = [
                'process_type' => $this->safeText($record['process_type'] ?? 'worker'),
                'queue_names' => is_array($record['queue_names'] ?? null) ? array_slice(array_map(fn ($v) => $this->safeText($v), $record['queue_names']), 0, 10) : [],
                'started_at' => $this->safeTimestamp($record['started_at'] ?? null),
                'heartbeat_at' => $this->safeTimestamp($record['last_heartbeat_at'] ?? null),
                'status' => $this->safeText($record['status'] ?? 'unknown'),
                'identifier' => $this->masked($record['process_id'] ?? null),
            ];
        }
        return ['expected_count' => $this->safeNumber($worker['expected_count'] ?? null), 'fresh_count' => $this->safeNumber($worker['fresh_count'] ?? null), 'records' => array_slice($records, 0, 50)];
    }

    private function safeText(mixed $value): string { return is_scalar($value) ? mb_substr((string) $value, 0, 80) : 'unknown'; }
    private function safeNumber(mixed $value): int|string { return is_numeric($value) ? max(0, min((int) $value, 2147483647)) : 'unknown'; }
    private function safeTimestamp(mixed $value): ?string { return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) ? mb_substr($value, 0, 40) : null; }
    private function masked(mixed $value): string { return is_string($value) && $value !== '' ? '••••'.substr(hash('sha256', $value), 0, 6) : 'unknown'; }
    private function unavailableReport(): array { return ['status' => 'failed', 'evaluated_at' => now()->toIso8601String(), 'queue' => ['connection' => 'unavailable', 'backlog' => 'unknown', 'oldest_job_age_seconds' => 'unknown', 'failed_jobs' => 'unknown'], 'lock_store' => ['cache' => 'unavailable', 'compatible' => false], 'worker' => ['expected_count' => 'unknown', 'fresh_count' => 'unknown', 'records' => []], 'scheduler' => ['fresh' => false, 'heartbeat_at' => null, 'status' => 'unavailable'], 'issues' => ['health_unavailable']]; }
}
