<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundScheduleFieldHelper;
use Illuminate\Support\Facades\DB;

final class UnscheduleOutboundMessageAction
{
    public function __construct(
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(User $user, string $id, int $scheduleVersion): OutboundMessage
    {
        return DB::transaction(function () use ($user, $id, $scheduleVersion): OutboundMessage {
            $message = OutboundMessage::query()
                ->whereKey($id)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                throw new OutboundSendException('not_found', 'Outbound message not found.', 404);
            }

            if ($message->state !== OutboundMessageState::Scheduled) {
                throw new OutboundSendException('message_not_scheduled', 'This message is not scheduled.', 422);
            }

            if ((int) $message->schedule_version !== $scheduleVersion) {
                throw new OutboundSendException('schedule_conflict', 'This schedule has changed. Refresh and try again.', 409);
            }

            if ($message->scheduled_claimed_at !== null) {
                throw new OutboundSendException('schedule_already_dispatched', 'This scheduled message is being dispatched.', 409);
            }

            $previousUtc = $message->scheduled_at?->toIso8601String();
            $previousTimezone = $message->scheduled_timezone;

            $message->forceFill([
                'state' => OutboundMessageState::Draft,
                ...OutboundScheduleFieldHelper::cleared(),
            ])->save();

            $this->auditLogWriter->write(
                'outbound.schedule_cancelled',
                (string) $user->getKey(),
                $message,
                ['state' => OutboundMessageState::Scheduled->value],
                ['state' => OutboundMessageState::Draft->value],
                [
                    'message_id' => (string) $message->getKey(),
                    'scheduled_at_utc' => $previousUtc,
                    'scheduled_timezone' => $previousTimezone,
                    'schedule_version' => $scheduleVersion,
                    'operation' => $message->operation->value,
                ],
            );

            return $message;
        });
    }
}
