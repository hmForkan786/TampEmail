<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Models\MailServer;
use Illuminate\Support\Collection;

/**
 * Pool monitoring snapshot for operators (no external APM required).
 */
final class MailServerPoolMonitor
{
    public function __construct(
        private readonly MailServerCapacityService $capacity,
        private readonly MailServerHealthScorer $scorer,
    ) {}

    /**
     * @return array{
     *     evaluated_at: string,
     *     pools: list<array<string, mixed>>,
     *     servers: list<array<string, mixed>>,
     *     summary: array<string, int>
     * }
     */
    public function snapshot(?string $poolKey = null): array
    {
        $query = MailServer::query()->orderBy('pool_key')->orderBy('priority', 'desc')->orderBy('id');
        if ($poolKey !== null && $poolKey !== '') {
            $query->where('pool_key', $poolKey);
        }

        /** @var Collection<int, MailServer> $servers */
        $servers = $query->get();
        $rows = [];
        $summary = [
            'servers' => 0,
            'active' => 0,
            'draining' => 0,
            'maintenance' => 0,
            'disabled' => 0,
            'eligible' => 0,
            'unhealthy' => 0,
        ];

        foreach ($servers as $server) {
            $status = $server->operationalStatusEnum();
            $metrics = $this->capacity->metrics($server);
            $liveScore = $this->scorer->score($server);
            $eligible = $this->scorer->isEligibleForAssignment($server)
                && $this->capacity->hasRemainingCapacity($server, $metrics['active_workload']);

            $summary['servers']++;
            $summary[$status->value]++;
            if ($eligible) {
                $summary['eligible']++;
            }
            if (! $this->scorer->isFresh($server) || $liveScore < (int) config('mail_servers.selection.min_health_score', 50)) {
                $summary['unhealthy']++;
            }

            $rows[] = [
                'id' => (string) $server->id,
                'name' => $server->name,
                'hostname' => $server->hostname,
                'pool_key' => $server->pool_key,
                'operational_status' => $status->value,
                'is_active' => (bool) $server->is_active,
                'health_score' => $liveScore,
                'stored_health_score' => (int) $server->health_score,
                'priority' => (int) $server->priority,
                'last_health_check_at' => $server->last_health_check_at?->toIso8601String(),
                'drain_started_at' => $server->drain_started_at?->toIso8601String(),
                'consecutive_failures' => (int) $server->consecutive_failures,
                'last_failure_at' => $server->last_failure_at?->toIso8601String(),
                'eligible_for_assignment' => $eligible,
                'capacity' => $metrics,
            ];
        }

        /** @var list<array{pool_key: string, servers: int, eligible: int, active_workload: int}> $pools */
        $pools = [];
        foreach (collect($rows)->groupBy(fn (array $r) => (string) ($r['pool_key'] ?? '')) as $key => $group) {
            if ($key === '') {
                continue;
            }
            $pools[] = [
                'pool_key' => (string) $key,
                'servers' => $group->count(),
                'eligible' => $group->where('eligible_for_assignment', true)->count(),
                'active_workload' => (int) $group->sum(fn (array $r) => $r['capacity']['active_workload']),
            ];
        }

        return [
            'evaluated_at' => now()->toIso8601String(),
            'pools' => $pools,
            'servers' => $rows,
            'summary' => $summary,
        ];
    }
}
