<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Narrow append-only writer for security and administrative audit records.
 */
class AuditLogWriter
{
    /**
     * Persist a new audit log entry.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function write(
        string $action,
        ?string $actorUserId = null,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?CarbonInterface $occurredAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        $log = new AuditLog;
        $log->user_id = $actorUserId;
        $log->action = $action;
        $log->auditable_type = $auditable !== null ? $auditable::class : null;
        $log->auditable_id = $auditable !== null ? (string) $auditable->getKey() : null;
        $log->ip_address = $ipAddress;
        $log->user_agent = $userAgent;
        $log->old_values = $oldValues;
        $log->new_values = $newValues;
        $log->metadata = $metadata;
        $log->created_at = $occurredAt ?? now();
        $log->save();

        return $log;
    }
}
