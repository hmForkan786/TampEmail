<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundNotificationService;
use App\Services\Outbound\OutboundScheduleFieldHelper;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Support\Facades\DB;

final class CancelOutboundMessageAction
{
    public function __construct(
        private readonly AuditLogWriter $auditLogWriter,
        private readonly OutboundUsageService $usage,
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

            if (! in_array($message->state, [OutboundMessageState::Queued, OutboundMessageState::Scheduled], true)) {
                throw new OutboundSendException('cancel_not_allowed', 'Only queued or scheduled messages can be cancelled.', 422);
            }

            $previousState = $message->state;
            $scheduleMetadata = $previousState === OutboundMessageState::Scheduled
                ? [
                    'scheduled_at_utc' => $message->scheduled_at?->toIso8601String(),
                    'scheduled_timezone' => $message->scheduled_timezone,
                    'schedule_version' => (int) $message->schedule_version,
                ]
                : [];

            $updates = [
                'state' => OutboundMessageState::Cancelled->value,
                'cancelled_at' => now(),
                'updated_at' => now(),
            ];

            if ($previousState === OutboundMessageState::Scheduled) {
                $updates = array_merge($updates, OutboundScheduleFieldHelper::cleared());
            }

            $updated = OutboundMessage::query()
                ->whereKey($message->getKey())
                ->where('state', $previousState->value)
                ->update($updates);

            if ($updated !== 1) {
                throw new OutboundSendException('cancel_not_allowed', 'Only queued or scheduled messages can be cancelled.', 422);
            }

            $fresh = $message->fresh();

            if ($previousState === OutboundMessageState::Queued) {
                $this->usage->release((string) $fresh->getKey(), 'cancelled_before_transport', (string) $user->getKey());
            }

            $this->auditLogWriter->write(
                'outbound.message_cancelled',
                (string) $user->getKey(),
                $fresh,
                ['state' => $previousState->value],
                ['state' => OutboundMessageState::Cancelled->value],
                [
                    'operation' => $fresh->operation->value,
                    'inbox_id' => (string) $fresh->inbox_id,
                    ...$scheduleMetadata,
                ],
            );
            app(OutboundNotificationService::class)->notify($user, 'outbound.cancelled', $fresh, [], 'cancelled:'.$fresh->id);

            return $fresh;
        });
    }
}
