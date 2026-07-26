<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundScheduleTimezone;
use Illuminate\Support\Facades\DB;

final class RescheduleOutboundMessageAction
{
    public function __construct(
        private readonly OutboundDraftService $drafts,
        private readonly OutboundScheduleTimezone $timezones,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(
        User $user,
        string $id,
        int $scheduleVersion,
        string $localDate,
        string $localTime,
        string $timezone,
        ?string $apiKeyId = null,
    ): OutboundMessage {
        if (! (bool) config('outbound.schedule.enabled', true)) {
            throw new OutboundSendException('message_not_scheduled', 'Scheduled sending is disabled.', 422);
        }

        $resolved = $this->timezones->resolveFutureLocal($localDate, $localTime, $timezone);

        return DB::transaction(function () use ($user, $id, $scheduleVersion, $resolved, $apiKeyId): OutboundMessage {
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

            $prepared = $this->drafts->prepareSendableContent($message, $user, $apiKeyId);
            $previousUtc = $message->scheduled_at?->toIso8601String();
            $nextVersion = (int) $message->schedule_version + 1;

            $message->forceFill([
                'to_recipients' => $prepared['to'],
                'cc_recipients' => $prepared['cc'],
                'bcc_recipients' => $prepared['bcc'],
                'subject' => $prepared['subject'],
                'text_body' => $prepared['text_body'],
                'html_body' => $prepared['html_body'],
                'attachment_ids' => $prepared['attachment_ids'],
                'in_reply_to' => $prepared['in_reply_to'],
                'references' => $prepared['references'],
                'request_fingerprint' => $prepared['request_fingerprint'],
                'is_canary' => $prepared['is_canary'],
                'scheduled_at' => $resolved['utc'],
                'scheduled_timezone' => $resolved['timezone'],
                'scheduled_by_user_id' => $user->getKey(),
                'schedule_version' => $nextVersion,
                'scheduled_claimed_at' => null,
                'schedule_defer_reason' => null,
                'schedule_next_attempt_at' => null,
            ])->save();

            $this->auditLogWriter->write(
                'outbound.schedule_updated',
                (string) $user->getKey(),
                $message,
                ['scheduled_at_utc' => $previousUtc],
                [
                    'scheduled_at_utc' => $resolved['utc']->toIso8601String(),
                    'scheduled_timezone' => $resolved['timezone'],
                ],
                [
                    'message_id' => (string) $message->getKey(),
                    'schedule_version' => $nextVersion,
                    'operation' => $message->operation->value,
                ],
            );

            return $message;
        });
    }
}
