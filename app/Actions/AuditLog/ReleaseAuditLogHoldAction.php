<?php
declare(strict_types=1);
namespace App\Actions\AuditLog;
use App\Models\AuditLogHold;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
final class ReleaseAuditLogHoldAction
{
    public function __construct(private readonly AuditLogWriter $auditLogWriter) {}
    public function execute(string $holdId, string $actorUserId): AuditLogHold
    {
        return DB::transaction(function () use ($holdId, $actorUserId): AuditLogHold {
            $actor = User::query()->whereKey($actorUserId)->lockForUpdate()->first();
            $hold = AuditLogHold::query()->whereKey($holdId)->lockForUpdate()->first();
            if (! $actor instanceof User || ! $actor->isPlatformAdmin()) throw new AuthorizationException('Only an active platform admin may release an audit hold.');
            if (! $hold instanceof AuditLogHold) throw new InvalidArgumentException('The audit hold does not exist.');
            if ($hold->released_at === null) {
                $hold->released_at = now(); $hold->released_by_user_id = $actor->getKey(); $hold->save();
                $this->auditLogWriter->write('audit_log.hold_released', (string)$actor->getKey(), $hold, null, null, ['target_audit_log_id'=>(string)$hold->audit_log_id,'hold_id'=>(string)$hold->getKey(),'released_at'=>$hold->released_at->toIso8601String()]);
            }
            return $hold;
        });
    }
}
