<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MailServerOperationalStatus;
use App\Models\MailServer;
use App\Services\MailServer\MailServerHeartbeatService;
use App\Services\MailServer\MailServerStatusTransitionService;
use Illuminate\Console\Command;

final class MailServersOpsCommand extends Command
{
    protected $signature = 'mail-servers:ops
        {action : heartbeat|failure|transition}
        {mailServer : Mail server UUID}
        {status? : Target status for transition (active|maintenance|draining|disabled)}
        {--reason= : Optional reason recorded in audit metadata}';

    protected $description = 'Operator heartbeat, failure report, or status transition for mail server inventory HA.';

    public function handle(
        MailServerHeartbeatService $heartbeat,
        MailServerStatusTransitionService $transitions,
    ): int {
        $server = MailServer::query()->whereKey((string) $this->argument('mailServer'))->first();
        if (! $server instanceof MailServer) {
            $this->error('mail_server_not_found');

            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        $reason = $this->option('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        try {
            $updated = match ($action) {
                'heartbeat' => $heartbeat->recordSuccess($server, null, 'cli'),
                'failure' => $heartbeat->recordFailure($server, null, 'cli', $reason),
                'transition' => $this->transition($transitions, $server, $reason),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($updated === null) {
            $this->error('invalid_action');

            return self::FAILURE;
        }

        $this->line(json_encode([
            'id' => (string) $updated->id,
            'operational_status' => $updated->operationalStatusEnum()->value,
            'is_active' => (bool) $updated->is_active,
            'health_score' => (int) $updated->health_score,
            'last_health_check_at' => $updated->last_health_check_at?->toIso8601String(),
            'consecutive_failures' => (int) $updated->consecutive_failures,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function transition(
        MailServerStatusTransitionService $transitions,
        MailServer $server,
        ?string $reason,
    ): MailServer {
        $raw = (string) ($this->argument('status') ?? '');
        $status = MailServerOperationalStatus::tryFrom($raw);
        if ($status === null) {
            throw new \InvalidArgumentException('status is required for transition (active|maintenance|draining|disabled).');
        }

        return $transitions->transition($server, $status, null, 'cli', $reason);
    }
}
