<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\DTOs\Outbound\CreateOutboundForwardData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Email;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundContentValidator;
use App\Services\Outbound\OutboundForwardContextBuilder;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundRateLimiter;
use App\Services\Outbound\OutboundRecipientValidator;
use App\Services\Outbound\OutboundSubjectHelper;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Support\Facades\DB;

final class CreateOutboundForwardAction
{
    public function __construct(
        private readonly OutboundAuthorizationService $authorization,
        private readonly OutboundRecipientValidator $recipients,
        private readonly OutboundContentValidator $content,
        private readonly OutboundSubjectHelper $subjects,
        private readonly OutboundForwardContextBuilder $forwardContext,
        private readonly OutboundAttachmentSelector $attachments,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly OutboundSuppressionService $suppressions,
        private readonly AuditLogWriter $auditLogWriter,
        private readonly OutboundLaunchControlService $launchControl,
    ) {}

    public function execute(CreateOutboundForwardData $data, User $user, ?string $apiKeyId = null): OutboundMessage
    {
        $email = Email::query()->with(['inbox.domain', 'body'])->find($data->emailId);
        if ($email === null || $email->trashed()) {
            throw new OutboundSendException('email_not_found', 'The original email was not found.', 404);
        }

        $inbox = $email->inbox;
        if ($inbox === null) {
            throw new OutboundSendException('inbox_not_found', 'The inbox was not found.', 404);
        }

        $this->authorization->assertCanSend($user, $inbox, OutboundOperation::Forward, $apiKeyId);

        if ($data->idempotencyKey === '' || preg_match('/[\r\n\0]/', $data->idempotencyKey) === 1 || mb_strlen($data->idempotencyKey) > 128) {
            throw new OutboundSendException('idempotency_key_invalid', 'A valid idempotency key is required.', 422);
        }

        $recipientSet = $this->recipients->validate($data->to, $data->cc, $data->bcc);
        $this->suppressions->assertRecipientsAllowed([
            ...$recipientSet['to'],
            ...$recipientSet['cc'],
            ...$recipientSet['bcc'],
        ], $user);
        $selectedAttachments = $this->attachments->selectForForward($email, $data->attachmentIds);

        $subject = $this->subjects->forwardSubject($email->subject, $data->subject);
        $textBody = $this->forwardContext->buildText($data->textBody, $email);
        $htmlBody = $data->htmlBody !== null
            ? $this->forwardContext->buildHtml($data->htmlBody, $email)
            : null;

        $content = $this->content->validate($subject, $textBody, $htmlBody, null);
        $attachmentIds = array_map(fn ($attachment): string => (string) $attachment->getKey(), $selectedAttachments);

        $fingerprint = hash('sha256', json_encode([
            'operation' => OutboundOperation::Forward->value,
            'source_email_id' => (string) $email->getKey(),
            'to' => $recipientSet['to'],
            'cc' => $recipientSet['cc'],
            'bcc' => $recipientSet['bcc'],
            'subject' => $content['subject'],
            'text_body' => $content['text_body'],
            'html_body' => $content['html_body'],
            'attachment_ids' => $attachmentIds,
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

        $this->rateLimiter->assertWithinLimits(
            $user,
            [
                ...$recipientSet['to'],
                ...$recipientSet['cc'],
                ...$recipientSet['bcc'],
            ],
            array_sum(array_map(static fn ($attachment): int => (int) $attachment->size_bytes, $selectedAttachments)),
        );

        $isCanary = $this->launchControl->isCanary($user, $inbox, $apiKeyId);

        $message = DB::transaction(function () use ($data, $user, $inbox, $email, $recipientSet, $content, $attachmentIds, $fingerprint, $apiKeyId, $isCanary): OutboundMessage {
            $message = OutboundMessage::query()->create([
                'user_id' => $user->getKey(),
                'inbox_id' => $inbox->getKey(),
                'source_email_id' => $email->getKey(),
                'operation' => OutboundOperation::Forward,
                'state' => OutboundMessageState::Queued,
                'idempotency_key' => $data->idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'from_address' => $inbox->full_address,
                'from_display_name' => null,
                'to_recipients' => $recipientSet['to'],
                'cc_recipients' => $recipientSet['cc'],
                'bcc_recipients' => $recipientSet['bcc'],
                'subject' => $content['subject'],
                'text_body' => $content['text_body'],
                'html_body' => $content['html_body'],
                'attachment_ids' => $attachmentIds,
                'attempt_count' => 0,
                'queued_at' => now(),
                'is_canary' => $isCanary,
            ]);

            $this->auditLogWriter->write('outbound.forward_created', (string) $user->getKey(), $message, null, [
                'state' => $message->state->value,
                'operation' => OutboundOperation::Forward->value,
            ], [
                'inbox_id' => (string) $inbox->getKey(),
                'source_email_id' => (string) $email->getKey(),
                'recipient_count' => $message->recipientCount(),
                'attachment_count' => count($attachmentIds),
                'api_key_id' => $apiKeyId,
            ]);

            $this->auditLogWriter->write('outbound.forward_queued', (string) $user->getKey(), $message, null, [
                'state' => OutboundMessageState::Queued->value,
            ], [
                'inbox_id' => (string) $inbox->getKey(),
                'operation' => OutboundOperation::Forward->value,
                'recipient_count' => $message->recipientCount(),
                'attachment_count' => count($attachmentIds),
            ]);

            return $message;
        });

        DeliverOutboundMessageJob::dispatch((string) $message->getKey());

        return $message;
    }
}
