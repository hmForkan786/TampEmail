<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\DTOs\Outbound\CreateOutboundReplyData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Email;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundContentValidator;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundRateLimiter;
use App\Services\Outbound\OutboundRecipientValidator;
use App\Services\Outbound\OutboundReplyRecipientResolver;
use App\Services\Outbound\OutboundSubjectHelper;
use App\Services\Outbound\OutboundSuppressionService;
use App\Services\Outbound\OutboundThreadingHeaders;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Support\Facades\DB;

final class CreateOutboundReplyAction
{
    public function __construct(
        private readonly OutboundAuthorizationService $authorization,
        private readonly OutboundReplyRecipientResolver $replyRecipients,
        private readonly OutboundRecipientValidator $recipients,
        private readonly OutboundContentValidator $content,
        private readonly OutboundSubjectHelper $subjects,
        private readonly OutboundThreadingHeaders $threading,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly OutboundSuppressionService $suppressions,
        private readonly AuditLogWriter $auditLogWriter,
        private readonly OutboundLaunchControlService $launchControl,
        private readonly OutboundUsageService $usage,
    ) {}

    public function execute(CreateOutboundReplyData $data, User $user, ?string $apiKeyId = null): OutboundMessage
    {
        $email = Email::query()->with(['inbox.domain', 'body'])->find($data->emailId);
        if ($email === null || $email->trashed()) {
            throw new OutboundSendException('email_not_found', 'The original email was not found.', 404);
        }

        $inbox = $email->inbox;
        if ($inbox === null) {
            throw new OutboundSendException('inbox_not_found', 'The inbox was not found.', 404);
        }

        $this->authorization->assertCanSend($user, $inbox, OutboundOperation::Reply, $apiKeyId);

        if ($data->idempotencyKey === '' || preg_match('/[\r\n\0]/', $data->idempotencyKey) === 1 || mb_strlen($data->idempotencyKey) > 128) {
            throw new OutboundSendException('idempotency_key_invalid', 'A valid idempotency key is required.', 422);
        }

        $to = [$this->replyRecipients->resolve($email)];
        $cc = [];
        if (config('outbound.reply_allow_cc', true) && $data->cc !== []) {
            $validated = $this->recipients->validate($to, $data->cc, []);
            $to = $validated['to'];
            $cc = $validated['cc'];
        }

        $this->suppressions->assertRecipientsAllowed([...$to, ...$cc], $user);

        $subject = $this->subjects->replySubject($email->subject, $data->subject);
        $textBody = $data->textBody;
        if ($data->includeQuote) {
            $textBody = $this->appendQuote($textBody, $email);
        }

        $content = $this->content->validate($subject, $textBody, $data->htmlBody, null);
        $headers = $this->threading->forReply($email);

        $fingerprint = hash('sha256', json_encode([
            'operation' => OutboundOperation::Reply->value,
            'source_email_id' => (string) $email->getKey(),
            'to' => $to,
            'cc' => $cc,
            'subject' => $content['subject'],
            'text_body' => $content['text_body'],
            'html_body' => $content['html_body'],
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

        $this->rateLimiter->assertWithinLimits($user, [...$to, ...$cc]);

        $isCanary = $this->launchControl->isCanary($user, $inbox, $apiKeyId);

        $message = DB::transaction(function () use ($data, $user, $inbox, $email, $to, $cc, $content, $headers, $fingerprint, $apiKeyId, $isCanary): OutboundMessage {
            $message = OutboundMessage::query()->create([
                'user_id' => $user->getKey(),
                'inbox_id' => $inbox->getKey(),
                'source_email_id' => $email->getKey(),
                'operation' => OutboundOperation::Reply,
                'state' => OutboundMessageState::Queued,
                'idempotency_key' => $data->idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'from_address' => $inbox->full_address,
                'from_display_name' => null,
                'to_recipients' => $to,
                'cc_recipients' => $cc,
                'bcc_recipients' => [],
                'subject' => $content['subject'],
                'text_body' => $content['text_body'],
                'html_body' => $content['html_body'],
                'in_reply_to' => $headers['in_reply_to'],
                'references' => $headers['references'],
                'attempt_count' => 0,
                'queued_at' => now(),
                'is_canary' => $isCanary,
            ]);

            $this->usage->reserve($user, $message, $data->idempotencyKey);

            $this->auditLogWriter->write('outbound.reply_created', (string) $user->getKey(), $message, null, [
                'state' => $message->state->value,
                'operation' => OutboundOperation::Reply->value,
            ], [
                'inbox_id' => (string) $inbox->getKey(),
                'source_email_id' => (string) $email->getKey(),
                'recipient_count' => $message->recipientCount(),
                'api_key_id' => $apiKeyId,
            ]);

            $this->auditLogWriter->write('outbound.reply_queued', (string) $user->getKey(), $message, null, [
                'state' => OutboundMessageState::Queued->value,
            ], [
                'inbox_id' => (string) $inbox->getKey(),
                'operation' => OutboundOperation::Reply->value,
                'recipient_count' => $message->recipientCount(),
            ]);

            return $message;
        });

        DeliverOutboundMessageJob::dispatch((string) $message->getKey());

        return $message;
    }

    private function appendQuote(?string $userBody, Email $email): ?string
    {
        $original = $email->body?->text_body;
        if ($original === null || trim($original) === '') {
            return $userBody;
        }

        $quoted = collect(preg_split("/\r\n|\n|\r/", mb_substr($original, 0, 5000)) ?: [])
            ->map(fn (string $line): string => '> '.$line)
            ->implode("\n");

        $intro = 'On '.$email->received_at?->toRfc7231String().', '.$email->sender_email." wrote:\n";
        $block = $intro.$quoted;

        if ($userBody === null || trim($userBody) === '') {
            return $block;
        }

        return rtrim($userBody)."\n\n".$block;
    }
}
