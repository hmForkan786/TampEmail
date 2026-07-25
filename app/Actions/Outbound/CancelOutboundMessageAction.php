<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

final class CancelOutboundMessageAction
{
    public function __construct(
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(string $messageId, User $user): OutboundMessage
    {
        return DB::transaction(function () use ($messageId, $user): OutboundMessage {
            $message = OutboundMessage::query()
                ->whereKey($messageId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                throw new OutboundSendException('not_found', 'Outbound message not found.', 404);
            }

            if ($message->state !== OutboundMessageState::Queued) {
                throw new OutboundSendException('cancel_not_allowed', 'Only queued messages can be cancelled.', 422);
            }

            $updated = OutboundMessage::query()
                ->whereKey($message->getKey())
                ->where('state', OutboundMessageState::Queued->value)
                ->update([
                    'state' => OutboundMessageState::Cancelled->value,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new OutboundSendException('cancel_not_allowed', 'Only queued messages can be cancelled.', 422);
            }

            $fresh = $message->fresh();
            $this->auditLogWriter->write(
                'outbound.message_cancelled',
                (string) $user->getKey(),
                $fresh,
                ['state' => OutboundMessageState::Queued->value],
                ['state' => OutboundMessageState::Cancelled->value],
                [
                    'operation' => $fresh->operation->value,
                    'inbox_id' => (string) $fresh->inbox_id,
                ],
            );

            return $fresh;
        });
    }
}
