<?php
declare(strict_types=1);
namespace App\Actions\Inbound;
use App\Models\InboundHold;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
final class ReleaseInboundHoldAction
{
    public function __construct(private readonly AuditLogWriter $audit) {}
    public function execute(string $holdId, string $actorUserId): InboundHold
    {
        return DB::transaction(function () use ($holdId,$actorUserId): InboundHold {
            $actor = User::query()->whereKey($actorUserId)->lockForUpdate()->first();
            $hold = InboundHold::query()->whereKey($holdId)->lockForUpdate()->first();
            if (! $actor?->isPlatformAdmin()) throw new AuthorizationException('Only an active platform admin may release an inbound hold.');
            if (! $hold) throw new InvalidArgumentException('Inbound hold does not exist.');
            if ($hold->released_at === null) { $hold->update(['released_at'=>now(),'released_by_user_id'=>$actor->id]); $this->audit->write('inbound_hold.released',(string)$actor->id,$hold,null,null,['target_type'=>$hold->target_type,'target_id'=>$hold->target_id,'hold_id'=>(string)$hold->id,'released_at'=>$hold->released_at->toIso8601String()]); }
            return $hold->fresh();
        });
    }
}
