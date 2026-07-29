<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Enums\MailServerOperationalStatus;
use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Services\MailServer\MailServerCapacityService;
use App\Services\MailServer\MailServerHealthScorer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Eloquent-backed persistence and query implementation for mail servers.
 *
 * @extends BaseEloquentRepository<MailServer, CreateMailServerData, UpdateMailServerData>
 */
final class EloquentMailServerRepository extends BaseEloquentRepository implements MailServerRepositoryInterface
{
    protected function model(): MailServer
    {
        return new MailServer;
    }

    public function findByHostname(string $hostname): ?MailServer
    {
        return $this->model()->newQuery()
            ->where('hostname', $hostname)
            ->first();
    }

    public function paginate(MailServerFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->provider !== null) {
            $query->where('provider', $filters->provider);
        }

        if ($filters->protocol !== null) {
            $query->where('protocol', $filters->protocol);
        }

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('hostname', 'like', "%{$search}%");
            });
        }

        if ($filters->hasSorting()) {
            $query->orderBy($filters->sortBy, $filters->sortDirection);
        }

        return $query->paginate($filters->perPage);
    }

    /**
     * Select and lock the best available mail server for the given pools.
     *
     * Deterministic routing (Prompt 652):
     * eligible (active status + fresh health + min score + under capacity)
     * → lowest utilization
     * → highest health_score
     * → highest priority
     * → lowest id (stable tie-break)
     *
     * Failover walks the ordered list (bounded). One selection per call —
     * no duplicate delivery / inbox rows.
     *
     * @param  array<string>  $poolKeys
     */
    public function selectAvailableForPoolsForUpdate(array $poolKeys): ?MailServer
    {
        if ($poolKeys === []) {
            return null;
        }

        $window = max(1, (int) config('mail_servers.health_window_minutes', 10));
        $minScore = (int) config('mail_servers.selection.min_health_score', 50);
        $maxEval = max(1, (int) config('mail_servers.failover.max_candidate_evaluations', 50));

        /** @var Collection<int, MailServer> $servers */
        $servers = $this->model()->newQuery()
            ->whereIn('pool_key', $poolKeys)
            ->where('is_active', true)
            ->where('operational_status', MailServerOperationalStatus::Active->value)
            ->whereNotNull('last_health_check_at')
            ->where('last_health_check_at', '>=', now()->subMinutes($window))
            ->where('health_score', '>=', $minScore)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($maxEval)
            ->lockForUpdate()
            ->get();

        if ($servers->isEmpty()) {
            return null;
        }

        $capacity = app(MailServerCapacityService::class);
        $scorer = app(MailServerHealthScorer::class);

        $ranked = [];
        foreach ($servers as $server) {
            if (! $scorer->isEligibleForAssignment($server)) {
                continue;
            }

            $workload = $capacity->activeWorkload($server, lockForUpdate: true);
            if (! $capacity->hasRemainingCapacity($server, $workload)) {
                continue;
            }

            $ranked[] = [
                'server' => $server,
                'utilization' => $capacity->utilizationSortKey($server, $workload),
                'health_score' => $scorer->score($server),
                'priority' => (int) $server->priority,
                'id' => (string) $server->id,
            ];
        }

        if ($ranked === []) {
            return null;
        }

        usort($ranked, static function (array $a, array $b): int {
            return [$a['utilization'], -$a['health_score'], -$a['priority'], $a['id']]
                <=> [$b['utilization'], -$b['health_score'], -$b['priority'], $b['id']];
        });

        return $ranked[0]['server'];
    }
}
