<?php
declare(strict_types=1);
namespace App\Actions\AuditLog;
use App\DTOs\AuditLog\CreateAuditLogHoldData;
use App\Models\AuditLog;
use App\Models\AuditLogHold;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
final class CreateAuditLogHoldAction
{
    public function __construct(private readonly AuditLogWriter $auditLogWriter) {}
    public function execute(CreateAuditLogHoldData $data): AuditLogHold
    {
        return DB::transaction(function () use ($data): AuditLogHold {
            $actor = User::query()->whereKey($data->heldByUserId)->lockForUpdate()->first();
            $log = AuditLog::query()->whereKey($data->auditLogId)->lockForUpdate()->first();
            if (! $actor instanceof User || ! $actor->isPlatformAdmin()) throw new AuthorizationException('Only an active platform admin may create an audit hold.');
            if (! $log instanceof AuditLog) throw new InvalidArgumentException('The audit log target does not exist.');
            if (trim($data->reason) === '' || mb_strlen($data->reason) > 500) throw new InvalidArgumentException('A bounded hold reason is required.');
            if (AuditLogHold::query()->where('audit_log_id', $log->getKey())->active()->exists()) throw new InvalidArgumentException('An active hold already exists for this audit log.');
            $hold = AuditLogHold::query()->create(['audit_log_id'=>$log->getKey(),'held_by_user_id'=>$actor->getKey(),'reason'=>trim($data->reason),'held_until'=>$data->heldUntil]);
            $this->auditLogWriter->write('audit_log.hold_created', (string)$actor->getKey(), $hold, null, null, ['target_audit_log_id'=>(string)$log->getKey(),'hold_id'=>(string)$hold->getKey(),'reason_redacted_or_safe'=>trim($data->reason),'held_until'=>$data->heldUntil?->toIso8601String()]);
            return $hold;
        });
    }
}
