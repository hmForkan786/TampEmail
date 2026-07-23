<?php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Services\Ops\ProcessReadinessService;
use Illuminate\Console\Command;
use Throwable;
final class ProcessHealth extends Command
{
    protected $signature = 'processes:health {--json}';
    protected $description = 'Report safe queue worker and scheduler process readiness.';
    public function handle(ProcessReadinessService $service): int
    {
        try {
            $report = $service->report();
            if (! is_array($report) || ! in_array($report['status'] ?? null, ['healthy', 'degraded', 'failed'], true)) {
                throw new \UnexpectedValueException('Invalid process readiness report.');
            }

            $this->option('json')
                ? $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->table(['field', 'value'], [
                    ['status', $report['status']],
                    ['queue_connection', $report['queue']['connection']],
                    ['worker_fresh_count', $report['worker']['fresh_count']],
                    ['scheduler_fresh', $report['scheduler']['fresh'] ? 'yes' : 'no'],
                    ['failed_jobs', $report['queue']['failed_jobs']],
                    ['backlog', $report['queue']['backlog']],
                ]);

            return $report['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $fallback = [
                'status' => 'failed',
                'evaluated_at' => now()->toIso8601String(),
                'reasons' => ['process_readiness_unavailable'],
            ];

            if ($this->option('json')) {
                $this->line(json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(['field', 'value'], [
                    ['status', 'failed'],
                    ['reason', 'process_readiness_unavailable'],
                ]);
            }

            return self::FAILURE;
        }
    }
}
