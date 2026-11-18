<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Enums\MailServerOperationalStatus;
use App\Models\MailServer;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Idempotent operational status transitions for mail server inventory HA.
 */
final class MailServerStatusTransitionService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly MailServerCapacityService $capacity,
        private readonly MailServerHealthScorer $scorer,
    ) {}

    public function transition(
        MailServer $server,
        MailServerOperationalStatus $to,
        ?User $actor = null,
        string $source = 'system',
        ?string $reason = null,
    ): MailServer {
        return DB::transaction(function () use ($server, $to, $actor, $source, $reason): MailServer {
            /** @var MailServer $locked */
            $locked = MailServer::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->operationalStatusEnum();

            if ($from === $to) {
                return $locked;
            }

            $this->assertAllowed($from, $to);

            $attributes = [
                'operational_status' => $to->value,
                'is_active' => $to === MailServerOperationalStatus::Active,
            ];

            if ($to === MailServerOperationalStatus::Draining && $locked->drain_started_at === null) {
                $attributes['drain_started_at'] = now();
            }

            if ($to === MailServerOperationalStatus::Active) {
                $attributes['drain_started_at'] = null;
            }

            if (in_array($to, [MailServerOperationalStatus::Maintenance, MailServerOperationalStatus::Disabled], true)
                && $from === MailServerOperationalStatus::Draining) {
                $attributes['drain_started_at'] = null;
            }

            $locked->forceFill($attributes);
            $locked->health_score = $this->scorer->score($locked);
            $locked->save();

            $this->audit->write(
                'mail_server.status_changed',
                $actor !== null ? (string) $actor->getKey() : null,
                $locked,
                ['operational_status' => $from->value, 'is_active' => $from === MailServerOperationalStatus::Active],
                ['operational_status' => $to->value, 'is_active' => $to === MailServerOperationalStatus::Active],
                array_filter([
                    'source' => $source,
                    'reason' => $reason,
                    'drain_started_at' => $locked->drain_started_at?->toIso8601String(),
                ], static fn ($v) => $v !== null && $v !== ''),
            );

            return $locked->refresh();
        });
    }

    /**
     * Complete draining when no active workload remains.
     */
    public function completeDrainIfIdle(MailServer $server): ?MailServer
    {
        if (! filter_var(config('mail_servers.maintenance.auto_complete_drain', true), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $rawTarget = (string) config('mail_servers.maintenance.drain_to_status', MailServerOperationalStatus::Maintenance->value);
        $target = MailServerOperationalStatus::tryFrom($rawTarget) ?? MailServerOperationalStatus::Maintenance;

        if (! in_array($target, [MailServerOperationalStatus::Maintenance, MailServerOperationalStatus::Disabled], true)) {
            $target = MailServerOperationalStatus::Maintenance;
        }

        $fresh = MailServer::query()->whereKey($server->getKey())->first();
        if (! $fresh instanceof MailServer) {
            return null;
        }

        if ($fresh->operationalStatusEnum() !== MailServerOperationalStatus::Draining) {
            return null;
        }

        if ($this->capacity->activeWorkload($fresh) > 0) {
            return null;
        }

        return $this->transition($fresh, $target, null, 'scheduler', 'drain_completed_idle');
    }

    private function assertAllowed(MailServerOperationalStatus $from, MailServerOperationalStatus $to): void
    {
        $allowed = match ($from) {
            MailServerOperationalStatus::Active => [
                MailServerOperationalStatus::Draining,
                MailServerOperationalStatus::Maintenance,
                MailServerOperationalStatus::Disabled,
            ],
            MailServerOperationalStatus::Draining => [
                MailServerOperationalStatus::Active,
                MailServerOperationalStatus::Maintenance,
                MailServerOperationalStatus::Disabled,
            ],
            MailServerOperationalStatus::Maintenance => [
                MailServerOperationalStatus::Active,
                MailServerOperationalStatus::Disabled,
                MailServerOperationalStatus::Draining,
            ],
            MailServerOperationalStatus::Disabled => [
                MailServerOperationalStatus::Active,
                MailServerOperationalStatus::Maintenance,
            ],
        };

        if (! in_array($to, $allowed, true)) {
            throw new DomainException("Mail server status transition {$from->value} → {$to->value} is not allowed.");
        }

        if ($from === MailServerOperationalStatus::Disabled && $to === MailServerOperationalStatus::Draining) {
            throw new InvalidArgumentException('Disabled servers must return to active or maintenance before draining.');
        }
    }
}
