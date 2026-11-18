<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Enums\MailServerOperationalStatus;
use App\Models\MailServer;
use Illuminate\Support\Carbon;

/**
 * Deterministic 0–100 health score for mail server inventory rows.
 *
 * Does not probe SMTP/MX. Inputs are operational status, heartbeat freshness,
 * and consecutive failure strikes recorded by operators/sidecars.
 */
final class MailServerHealthScorer
{
    public function score(MailServer $server, ?Carbon $now = null): int
    {
        $now ??= now();
        $status = $this->statusOf($server);

        if (! $status->acceptsNewAssignments()) {
            return 0;
        }

        $scoring = config('mail_servers.scoring', []);
        $activePoints = max(0, (int) ($scoring['active_status_points'] ?? 40));
        $freshPoints = max(0, (int) ($scoring['fresh_check_points'] ?? 40));
        $zeroFailurePoints = max(0, (int) ($scoring['zero_failure_points'] ?? 20));
        $penalty = max(0, (int) ($scoring['failure_penalty_per_strike'] ?? 10));
        $maxStrikes = max(1, (int) ($scoring['max_failure_strikes'] ?? 10));

        $score = $activePoints;

        if ($this->hasFreshHealthCheck($server, $now)) {
            $score += $freshPoints;
        }

        $strikes = min($maxStrikes, max(0, (int) $server->consecutive_failures));
        if ($strikes === 0) {
            $score += $zeroFailurePoints;
        } else {
            $score -= min($zeroFailurePoints + ($penalty * $strikes), $score);
        }

        return max(0, min(100, $score));
    }

    public function isFresh(MailServer $server, ?Carbon $now = null): bool
    {
        return $this->hasFreshHealthCheck($server, $now ?? now());
    }

    public function isEligibleForAssignment(MailServer $server, ?Carbon $now = null): bool
    {
        $now ??= now();
        $status = $this->statusOf($server);
        $min = (int) config('mail_servers.selection.min_health_score', 50);

        return $server->is_active
            && $status->acceptsNewAssignments()
            && $this->hasFreshHealthCheck($server, $now)
            && $this->score($server, $now) >= $min;
    }

    private function hasFreshHealthCheck(MailServer $server, Carbon $now): bool
    {
        if ($server->last_health_check_at === null) {
            return false;
        }

        $window = max(1, (int) config('mail_servers.health_window_minutes', 10));

        return $server->last_health_check_at->gte($now->copy()->subMinutes($window));
    }

    private function statusOf(MailServer $server): MailServerOperationalStatus
    {
        return $server->operationalStatusEnum();
    }
}
