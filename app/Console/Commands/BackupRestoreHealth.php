<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\BackupRestoreReadinessService;
use Illuminate\Console\Command;
use Throwable;

final class BackupRestoreHealth extends Command
{
    protected $signature = 'backup:restore-health {--json : Print a JSON summary}';

    protected $description = 'Verify database and attachment-storage backup/restore readiness without exporting or restoring data.';

    public function handle(BackupRestoreReadinessService $service): int
    {
        try {
            $report = $service->report();

            if (! is_array($report) || ! in_array($report['status'] ?? null, ['ready', 'degraded', 'failed'], true)) {
                throw new \UnexpectedValueException('Invalid backup restore readiness report.');
            }
        } catch (Throwable) {
            $report = [
                'status' => 'failed',
                'issues' => ['backup_restore_readiness_unavailable'],
                'database' => ['configured' => false, 'reachable' => false, 'driver' => 'unknown'],
                'storage' => [
                    'attachments' => [
                        'configured' => false,
                        'reachable' => false,
                        'visibility' => 'unknown',
                        'integrity' => 'skipped',
                    ],
                    'message_bodies' => [
                        'configured' => false,
                        'reachable' => false,
                        'visibility' => 'unknown',
                        'integrity' => 'skipped',
                    ],
                ],
                'manifest' => ['present' => false, 'integrity' => 'invalid'],
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['field', 'value'], [
                ['status', $report['status']],
                ['issues', implode(',', $report['issues'] ?? []) ?: 'none'],
                ['database_reachable', ($report['database']['reachable'] ?? false) ? 'yes' : 'no'],
                ['database_driver', $report['database']['driver'] ?? 'unknown'],
                ['attachments_reachable', ($report['storage']['attachments']['reachable'] ?? false) ? 'yes' : 'no'],
                ['message_bodies_reachable', ($report['storage']['message_bodies']['reachable'] ?? false) ? 'yes' : 'no'],
                ['manifest_integrity', $report['manifest']['integrity'] ?? 'invalid'],
            ]);
        }

        return match ($report['status']) {
            'ready' => self::SUCCESS,
            'degraded' => 2,
            default => self::FAILURE,
        };
    }
}
