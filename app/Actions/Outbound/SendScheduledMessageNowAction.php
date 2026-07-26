<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundRateLimiter;
use App\Services\Outbound\OutboundScheduleFieldHelper;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Support\Facades\DB;

final class SendScheduledMessageNowAction
{
    public function __construct(
        private readonly OutboundDraftService $drafts,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly OutboundUsageService $usage,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(User $user, string $id, int $scheduleVersion, ?string $apiKeyId = null): OutboundMessage
    {
        $dispatchId = null;

        $message = DB::transaction(function () use ($user, $id, $scheduleVersion, $apiKeyId, &$dispatchId): OutboundMessage {
            $locked = OutboundMessage::query()
                ->whereKey($id)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new OutboundSendException('not_found', 'Outbound message not found.', 404);
            }

            if ($locked->state === OutboundMessageState::Queued) {
                return $locked;
            }

            if ($locked->state !== OutboundMessageState::Scheduled) {
                throw new OutboundSendException('message_not_scheduled', 'This message is not scheduled.', 422);
            }

            if ((int) $locked->schedule_version !== $scheduleVersion) {
                throw new OutboundSendException('schedule_conflict', 'This schedule has changed. Refresh and try again.', 409);
            }

            if ($locked->scheduled_claimed_at !== null) {
                throw new OutboundSendException('schedule_already_dispatched', 'This scheduled message is being dispatched.', 409);
            }

            $prepared = $this->drafts->prepareSendableContent($locked, $user, $apiKeyId);
            $this->rateLimiter->assertWithinLimits($user, [...$prepared['to'], ...$prepared['cc'], ...$prepared['bcc']], $prepared['attachment_bytes']);

            $locked->forceFill([
                'state' => OutboundMessageState::Queued,
                'to_recipients' => $prepared['to'],
                'cc_recipients' => $prepared['cc'],
                'bcc_recipients' => $prepared['bcc'],
                'subject' => $prepared['subject'],
                'text_body' => $prepared['text_body'],
                'html_body' => $prepared['html_body'],
                'from_display_name' => $prepared['from_display_name'],
                'reply_to_address' => $prepared['reply_to_address'],
                'reply_to_name' => $prepared['reply_to_name'],
                'attachment_ids' => $prepared['attachment_ids'],
                'in_reply_to' => $prepared['in_reply_to'],
                'references' => $prepared['references'],
                'request_fingerprint' => $prepared['request_fingerprint'],
                'is_canary' => $prepared['is_canary'],
                'queued_at' => now(),
                'draft_submitted_at' => $locked->draft_submitted_at ?? now(),
                ...OutboundScheduleFieldHelper::cleared(),
            ])->save();

            $this->usage->reserve($user, $locked, $locked->idempotency_key, $prepared['attachment_bytes']);

            $this->auditLogWriter->write(
                'outbound.schedule_dispatched',
                (string) $user->getKey(),
                $locked,
                ['state' => OutboundMessageState::Scheduled->value],
                ['state' => OutboundMessageState::Queued->value],
                [
                    'message_id' => (string) $locked->getKey(),
                    'schedule_version' => $scheduleVersion,
                    'operation' => $locked->operation->value,
                ],
            );

            $dispatchId = (string) $locked->getKey();

            return $locked;
        });

        if ($dispatchId !== null && $message->state === OutboundMessageState::Queued) {
            DeliverOutboundMessageJob::dispatch($dispatchId)->afterCommit();
        }

        return $message;
    }
}
