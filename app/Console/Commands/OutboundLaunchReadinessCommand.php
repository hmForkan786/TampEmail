<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundLaunchReadinessService;
use Illuminate\Console\Command;

/**
 * Production launch readiness gate. Never sends a test email — see
 * `outbound:canary-send` for the explicit, opt-in probe.
 */
final class OutboundLaunchReadinessCommand extends Command
{
    protected $signature = 'outbound:launch-readiness {--json : Print a JSON summary}';

    protected $description = 'Evaluate outbound production launch readiness: transport, provider, queues, workers, domain verification, flags, and rollout controls.';

    public function handle(OutboundLaunchReadinessService $service): int
    {
        try {
            $report = $service->evaluate();
        } catch (\Throwable) {
            $report = [
                'status' => 'blocked',
                'reasons' => ['readiness_unavailable'],
                'evaluated_at' => now()->toIso8601String(),
                'checks' => [],
            ];
        }

        $safe = $this->safeReport($report);

        if ($this->option('json')) {
            $this->line(json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$safe['status']);
            foreach ($safe['reasons'] as $reason) {
                $this->line('- '.$reason);
            }
        }

        return match ($safe['status']) {
            'ready' => self::SUCCESS,
            'degraded', 'disabled' => 2,
            default => self::FAILURE,
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function safeReport(array $report): array
    {
        $status = $report['status'] ?? 'blocked';
        if (! in_array($status, ['ready', 'degraded', 'blocked', 'disabled'], true)) {
            $status = 'blocked';
        }

        return [
            'status' => $status,
            'evaluated_at' => is_string($report['evaluated_at'] ?? null) ? $report['evaluated_at'] : now()->toIso8601String(),
            'reasons' => array_values(array_filter(
                is_array($report['reasons'] ?? null) ? $report['reasons'] : [],
                fn ($reason): bool => is_string($reason) && preg_match('/^[a-z0-9_]{1,80}$/', $reason) === 1,
            )),
            'checks' => is_array($report['checks'] ?? null) ? $report['checks'] : [],
        ];
    }
}
