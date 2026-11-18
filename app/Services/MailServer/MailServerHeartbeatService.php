<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Models\MailServer;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Operator/sidecar heartbeat and failure reporting for inventory health.
 *
 * Does not open SMTP connections. Updates timestamps and health_score only.
 */
final class MailServerHeartbeatService
{
    public function __construct(
        private readonly MailServerHealthScorer $scorer,
        private readonly AuditLogWriter $audit,
    ) {}

    public function recordSuccess(MailServer $server, ?User $actor = null, string $source = 'system'): MailServer
    {
        return DB::transaction(function () use ($server, $actor, $source): MailServer {
            /** @var MailServer $locked */
            $locked = MailServer::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'last_health_check_at' => now(),
                'consecutive_failures' => 0,
                'last_failure_at' => null,
            ]);
            $locked->health_score = $this->scorer->score($locked);
            $locked->save();

            $this->audit->write(
                'mail_server.heartbeat_recorded',
                $actor !== null ? (string) $actor->getKey() : null,
                $locked,
                null,
                ['health_score' => $locked->health_score, 'last_health_check_at' => $locked->last_health_check_at?->toIso8601String()],
                ['source' => $source, 'result' => 'success'],
            );

            return $locked->refresh();
        });
    }

    public function recordFailure(MailServer $server, ?User $actor = null, string $source = 'system', ?string $reason = null): MailServer
    {
        return DB::transaction(function () use ($server, $actor, $source, $reason): MailServer {
            /** @var MailServer $locked */
            $locked = MailServer::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $max = max(1, (int) config('mail_servers.scoring.max_failure_strikes', 10));
            $locked->forceFill([
                'consecutive_failures' => min($max, (int) $locked->consecutive_failures + 1),
                'last_failure_at' => now(),
            ]);
            $locked->health_score = $this->scorer->score($locked);
            $locked->save();

            $this->audit->write(
                'mail_server.failure_recorded',
                $actor !== null ? (string) $actor->getKey() : null,
                $locked,
                null,
                [
                    'health_score' => $locked->health_score,
                    'consecutive_failures' => $locked->consecutive_failures,
                    'last_failure_at' => $locked->last_failure_at?->toIso8601String(),
                ],
                array_filter(['source' => $source, 'reason' => $reason], static fn ($v) => $v !== null && $v !== ''),
            );

            return $locked->refresh();
        });
    }
}
