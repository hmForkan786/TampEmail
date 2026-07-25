<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\DTOs\Outbound\CreateOutboundSendData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundContentValidator;
use App\Services\Outbound\OutboundRateLimiter;
use App\Services\Outbound\OutboundRecipientValidator;
use Illuminate\Support\Facades\DB;

final class CreateOutboundSendAction
{
    public function __construct(
        private readonly OutboundAuthorizationService $authorization,
        private readonly OutboundRecipientValidator $recipients,
        private readonly OutboundContentValidator $content,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(CreateOutboundSendData $data, User $user, ?string $apiKeyId = null): OutboundMessage
    {
        $inbox = Inbox::query()->with('domain')->find($data->inboxId);
        if ($inbox === null) {
            throw new OutboundSendException('inbox_not_found', 'The inbox was not found.', 404);
        }

        $this->authorization->assertCanSend($user, $inbox, OutboundOperation::Send);

        if ($data->idempotencyKey === '' || preg_match('/[\r\n\0]/', $data->idempotencyKey) === 1) {
            throw new OutboundSendException('idempotency_key_invalid', 'A valid idempotency key is required.', 422);
        }

        if (mb_strlen($data->idempotencyKey) > 128) {
            throw new OutboundSendException('idempotency_key_invalid', 'The idempotency key is too long.', 422);
        }

        $recipientSet = $this->recipients->validate($data->to, $data->cc, $data->bcc);
        $content = $this->content->validate(
            $data->subject,
            $data->textBody,
            $data->htmlBody,
            $data->fromDisplayName,
        );

        $fingerprint = hash('sha256', json_encode([
            'operation' => OutboundOperation::Send->value,
            'inbox_id' => (string) $inbox->getKey(),
            'to' => $recipientSet['to'],
            'cc' => $recipientSet['cc'],
            'bcc' => $recipientSet['bcc'],
            'subject' => $content['subject'],
            'text_body' => $content['text_body'],
            'html_body' => $content['html_body'],
            'from_display_name' => $content['from_display_name'],
        ], JSON_THROW_ON_ERROR));

        $existing = OutboundMessage::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->request_fingerprint !== $fingerprint) {
                throw new OutboundSendException('idempotency_conflict', 'The idempotency key was reused with a different payload.', 409);
            }

            return $existing;
        }

        $this->rateLimiter->assertWithinLimits($user);

        $message = DB::transaction(function () use ($data, $user, $inbox, $recipientSet, $content, $fingerprint, $apiKeyId): OutboundMessage {
            $message = OutboundMessage::query()->create([
                'user_id' => $user->getKey(),
                'inbox_id' => $inbox->getKey(),
                'source_email_id' => null,
                'operation' => OutboundOperation::Send,
                'state' => OutboundMessageState::Queued,
                'idempotency_key' => $data->idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'from_address' => $inbox->full_address,
                'from_display_name' => $content['from_display_name'],
                'to_recipients' => $recipientSet['to'],
                'cc_recipients' => $recipientSet['cc'],
                'bcc_recipients' => $recipientSet['bcc'],
                'subject' => $content['subject'],
                'text_body' => $content['text_body'],
                'html_body' => $content['html_body'],
                'attempt_count' => 0,
                'queued_at' => now(),
            ]);

            $this->auditLogWriter->write(
                'outbound.message_created',
                (string) $user->getKey(),
                $message,
                null,
                [
                    'state' => $message->state->value,
                    'operation' => $message->operation->value,
                ],
                [
                    'inbox_id' => (string) $inbox->getKey(),
                    'recipient_count' => $message->recipientCount(),
                    'api_key_id' => $apiKeyId,
                ],
            );

            $this->auditLogWriter->write(
                'outbound.message_queued',
                (string) $user->getKey(),
                $message,
                null,
                ['state' => OutboundMessageState::Queued->value],
                [
                    'inbox_id' => (string) $inbox->getKey(),
                    'operation' => OutboundOperation::Send->value,
                    'recipient_count' => $message->recipientCount(),
                ],
            );

            return $message;
        });

        DeliverOutboundMessageJob::dispatch((string) $message->getKey());

        return $message;
    }
}
