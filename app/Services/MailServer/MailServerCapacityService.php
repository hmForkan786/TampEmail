<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Models\MailServer;
use Illuminate\Support\Facades\DB;

/**
 * Application-side capacity metrics for mail server inventory.
 */
final class MailServerCapacityService
{
    /**
     * Count active, non-expired, non-deleted inboxes assigned to the server.
     */
    public function activeWorkload(MailServer $server, bool $lockForUpdate = false): int
    {
        $query = DB::table('inboxes')
            ->where('mail_server_id', $server->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return (int) $query->count();
    }

    /**
     * @return array{
     *     active_workload: int,
     *     max_inboxes: int|null,
     *     max_throughput: int|null,
     *     remaining_capacity: int|null,
     *     utilization: float|null,
     *     unlimited: bool
     * }
     */
    public function metrics(MailServer $server, ?int $activeWorkload = null): array
    {
        $active = $activeWorkload ?? $this->activeWorkload($server);
        $maxInboxes = $server->max_inboxes;
        $unlimited = $maxInboxes === null;
        $remaining = $unlimited ? null : max(0, (int) $maxInboxes - $active);
        $utilization = $unlimited || (int) $maxInboxes === 0
            ? null
            : round($active / (int) $maxInboxes, 4);

        return [
            'active_workload' => $active,
            'max_inboxes' => $maxInboxes,
            'max_throughput' => $server->max_throughput,
            'remaining_capacity' => $remaining,
            'utilization' => $utilization,
            'unlimited' => $unlimited,
        ];
    }

    public function hasRemainingCapacity(MailServer $server, ?int $activeWorkload = null): bool
    {
        if ($server->max_inboxes === null) {
            return true;
        }

        $active = $activeWorkload ?? $this->activeWorkload($server);

        return $active < (int) $server->max_inboxes;
    }

    /**
     * Sort key: lower is better. Unlimited capacity sorts as 0.0 utilization.
     */
    public function utilizationSortKey(MailServer $server, int $activeWorkload): float
    {
        if ($server->max_inboxes === null || (int) $server->max_inboxes <= 0) {
            return 0.0;
        }

        return $activeWorkload / (int) $server->max_inboxes;
    }
}
