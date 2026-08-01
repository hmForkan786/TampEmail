<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Enums\MailServerOperationalStatus;
use App\Models\MailServer;

/**
 * Refresh stored health scores and complete idle drains (idempotent).
 */
final class MailServerHaRefreshService
{
    public function __construct(
        private readonly MailServerHealthScorer $scorer,
        private readonly MailServerStatusTransitionService $transitions,
    ) {}

    /**
     * @return array{refreshed: int, drains_completed: int}
     */
    public function refresh(?int $limit = null): array
    {
        $limit ??= max(1, (int) config('mail_servers.refresh_batch_size', 100));
        $refreshed = 0;
        $drainsCompleted = 0;

        $servers = MailServer::query()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($servers as $server) {
            $score = $this->scorer->score($server);
            if ((int) $server->health_score !== $score) {
                $server->forceFill(['health_score' => $score])->save();
                $refreshed++;
            } else {
                $refreshed++;
            }

            if ($server->operationalStatusEnum() === MailServerOperationalStatus::Draining) {
                $completed = $this->transitions->completeDrainIfIdle($server);
                if ($completed !== null) {
                    $drainsCompleted++;
                }
            }
        }

        return [
            'refreshed' => $refreshed,
            'drains_completed' => $drainsCompleted,
        ];
    }
}
