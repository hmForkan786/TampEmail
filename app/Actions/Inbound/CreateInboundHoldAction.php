<?php
declare(strict_types=1);
namespace App\Actions\Inbound;
use App\DTOs\Inbound\CreateInboundHoldData;
use App\Models\InboundHold;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
final class CreateInboundHoldAction
{
    public function __construct(private readonly AuditLogWriter $audit) {}
    public function execute(CreateInboundHoldData $data): InboundHold
    {
        return DB::transaction(function () use ($data): InboundHold {
            $actor = User::query()->whereKey($data->heldByUserId)->lockForUpdate()->first();
            if (! $actor?->isPlatformAdmin()) throw new AuthorizationException('Only an active platform admin may create an inbound hold.');
            if (! in_array($data->targetType, ['email','attachment','inbox'], true) || ! class_exists($this->targetClass($data->targetType)) || ! $this->targetClass($data->targetType)::query()->whereKey($data->targetId)->exists()) throw new InvalidArgumentException('Inbound hold target does not exist.');
            if (trim($data->reason) === '' || mb_strlen($data->reason) > 500) throw new InvalidArgumentException('A bounded hold reason is required.');
            if (InboundHold::query()->where('target_type',$data->targetType)->where('target_id',$data->targetId)->active()->exists()) throw new InvalidArgumentException('An active inbound hold already exists.');
            $hold = InboundHold::query()->create(['target_type'=>$data->targetType,'target_id'=>$data->targetId,'held_by_user_id'=>$actor->id,'reason'=>trim($data->reason),'held_until'=>$data->heldUntil]);
            $this->audit->write('inbound_hold.created',(string)$actor->id,$hold,null,null,['target_type'=>$data->targetType,'target_id'=>$data->targetId,'hold_id'=>(string)$hold->id,'held_until'=>$data->heldUntil?->toIso8601String()]);
            return $hold;
        });
    }
    private function targetClass(string $type): string { return ['email'=>\App\Models\Email::class,'attachment'=>\App\Models\Attachment::class,'inbox'=>\App\Models\Inbox::class][$type] ?? ''; }
}
