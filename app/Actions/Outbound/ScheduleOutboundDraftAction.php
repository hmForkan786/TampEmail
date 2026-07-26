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

final class ScheduleOutboundDraftAction
{
    public function __construct(
        private readonly OutboundDraftService $drafts,
        private readonly OutboundScheduleTimezone $timezones,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(
        User $user,
        string $id,
        int $draftVersion,
        string $localDate,
        string $localTime,
        string $timezone,
        ?string $apiKeyId = null,
    ): OutboundMessage {
        if (! (bool) config('outbound.schedule.enabled', true)) {
            throw new OutboundSendException('message_not_schedulable', 'Scheduled sending is disabled.', 422);
        }

        $resolved = $this->timezones->resolveFutureLocal($localDate, $localTime, $timezone);

        return DB::transaction(function () use ($user, $id, $draftVersion, $resolved, $apiKeyId): OutboundMessage {
            $draft = OutboundMessage::query()
                ->whereKey($id)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($draft === null || $draft->draft_deleted_at !== null) {
                throw new OutboundSendException('draft_not_found', 'Draft not found.', 404);
            }

            if ($draft->state === OutboundMessageState::Scheduled) {
                return $draft;
            }

            if (! $draft->state->isSchedulable()) {
                throw new OutboundSendException('message_not_schedulable', 'This message cannot be scheduled.', 422);
            }

            if ($draft->draft_version !== $draftVersion) {
                throw new OutboundSendException('draft_conflict', 'This draft has changed. Refresh and try again.', 409);
            }

            $prepared = $this->drafts->prepareSendableContent($draft, $user, $apiKeyId);
            $scheduleVersion = (int) $draft->schedule_version + 1;

            $draft->forceFill([
                'state' => OutboundMessageState::Scheduled,
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
                'schedule_version' => $scheduleVersion,
                'scheduled_claimed_at' => null,
                'schedule_defer_reason' => null,
                'schedule_next_attempt_at' => null,
            ])->save();

            $this->auditLogWriter->write(
                'outbound.schedule_created',
                (string) $user->getKey(),
                $draft,
                ['state' => OutboundMessageState::Draft->value],
                ['state' => OutboundMessageState::Scheduled->value],
                [
                    'message_id' => (string) $draft->getKey(),
                    'scheduled_at_utc' => $resolved['utc']->toIso8601String(),
                    'scheduled_timezone' => $resolved['timezone'],
                    'schedule_version' => $scheduleVersion,
                    'operation' => $draft->operation->value,
                ],
            );

            return $draft;
        });
    }
}
