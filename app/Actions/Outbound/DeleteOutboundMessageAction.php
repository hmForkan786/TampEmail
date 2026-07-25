<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundPruneService;
use Illuminate\Support\Facades\DB;

/**
 * User-initiated hide ("soft delete") of an outbound message.
 *
 * Never rewrites transport state and never hard-deletes anything. Queued
 * messages are cancelled first (via {@see CancelOutboundMessageAction}, the
 * same rule the API/web cancel affordance uses) so a hidden message never
 * silently keeps sending in the background; already sending/sent/delivered
 * messages are hidden as-is because deletion alone must never cancel or
 * rewrite an in-flight or completed send. Hard deletion of the row itself
 * is a separate, much later step performed only by
 * {@see OutboundPruneService}.
 */
final class DeleteOutboundMessageAction
{
    public function __construct(
        private readonly CancelOutboundMessageAction $cancelOutboundMessage,
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

            if ($message->isUserDeleted()) {
                throw new OutboundSendException('already_deleted', 'Outbound message already deleted.', 409);
            }

            $stateBeforeHide = $message->state;
            $wasCancelled = false;

            if ($message->state === OutboundMessageState::Queued) {
                $message = $this->cancelOutboundMessage->execute($message->getKey(), $user);
                $wasCancelled = true;
            }

            $updated = OutboundMessage::query()
                ->whereKey($message->getKey())
                ->whereNull('user_deleted_at')
                ->update([
                    'user_deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new OutboundSendException('already_deleted', 'Outbound message already deleted.', 409);
            }

            $fresh = $message->fresh();

            $this->auditLogWriter->write(
                'outbound.message_user_deleted',
                (string) $user->getKey(),
                $fresh,
                ['user_deleted_at' => null],
                ['user_deleted_at' => $fresh->user_deleted_at?->toIso8601String()],
                [
                    'state_before_hide' => $stateBeforeHide->value,
                    'state' => $fresh->state->value,
                    'cancelled_first' => $wasCancelled,
                ],
            );

            return $fresh;
        });
    }
}
