<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReleaseOutboundRetentionHoldAction
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function execute(string $messageId, string $actorUserId): OutboundMessage
    {
        return DB::transaction(function () use ($messageId, $actorUserId): OutboundMessage {
            $actor = User::query()->whereKey($actorUserId)->lockForUpdate()->first();

            if (! $actor?->isPlatformAdmin()) {
                throw new AuthorizationException('Only an active platform admin may release an outbound retention hold.');
            }

            $message = OutboundMessage::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($message === null) {
                throw new InvalidArgumentException('Outbound message does not exist.');
            }

            if ($message->retention_hold_reason_code === null) {
                return $message;
            }

            $previousReason = $message->retention_hold_reason_code;

            $message->forceFill([
                'retention_hold_until' => null,
                'retention_hold_reason_code' => null,
            ])->save();

            $fresh = $message->fresh();

            $this->audit->write(
                'outbound.retention_hold_released',
                (string) $actor->id,
                $fresh,
                ['retention_hold_reason_code' => $previousReason],
                ['retention_hold_reason_code' => null],
                [
                    'message_id' => (string) $fresh->id,
                ],
            );

            return $fresh;
        });
    }
}
