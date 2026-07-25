<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundMessageData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundTransportResult;
use App\Enums\UserStatus;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class DeliverOutboundMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $outboundMessageId)
    {
        $this->onQueue((string) config('queue.workloads.outbound_delivery', 'outbound-delivery'));
    }

    public function uniqueId(): string
    {
        return $this->outboundMessageId;
    }

    public function tries(): int
    {
        return max(1, (int) config('outbound.send_max_attempts', 3));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('outbound.send_backoff_seconds', [60, 300, 900]);

        return is_array($backoff) && $backoff !== [] ? array_values(array_map('intval', $backoff)) : [60, 300, 900];
    }

    public function handle(
        OutboundTransportInterface $transport,
        OutboundAuthorizationService $authorization,
        AuditLogWriter $audit,
        OutboundAttachmentSelector $attachmentSelector,
    ): void {
        $claimed = DB::transaction(function (): ?OutboundMessage {
            $message = OutboundMessage::query()
                ->whereKey($this->outboundMessageId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return null;
            }

            if ($message->state !== OutboundMessageState::Queued) {
                return null;
            }

            $updated = OutboundMessage::query()
                ->whereKey($message->getKey())
                ->where('state', OutboundMessageState::Queued->value)
                ->update([
                    'state' => OutboundMessageState::Sending->value,
                    'sending_at' => now(),
                    'attempt_count' => $message->attempt_count + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                return null;
            }

            return $message->fresh();
        });

        if ($claimed === null) {
            return;
        }

        $audit->write(
            'outbound.message_sending',
            (string) $claimed->user_id,
            $claimed,
            ['state' => OutboundMessageState::Queued->value],
            ['state' => OutboundMessageState::Sending->value],
            [
                'attempt' => $claimed->attempt_count,
                'operation' => $claimed->operation->value,
                'inbox_id' => (string) $claimed->inbox_id,
                'recipient_count' => $claimed->recipientCount(),
            ],
        );

        $claimed->load(['user', 'inbox.domain', 'sourceEmail']);

        try {
            if ($claimed->user === null || $claimed->user->status !== UserStatus::Active) {
                $this->markFailed($claimed, 'user_inactive', 'User is no longer active.', $audit);

                return;
            }

            $authorization->assertCanSend($claimed->user, $claimed->inbox, $claimed->operation);
        } catch (\Throwable $exception) {
            $code = property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'authorization_failed';
            $this->markFailed($claimed, $code, 'Authorization failed before transport submission.', $audit);

            return;
        }

        $transportAttachments = [];
        if ($claimed->operation === OutboundOperation::Forward && ($claimed->attachment_ids ?? []) !== []) {
            try {
                $source = $claimed->sourceEmail;
                if ($source === null) {
                    throw new OutboundSendException('email_not_found', 'The original email was not found.', 404);
                }
                $selected = $attachmentSelector->selectForForward($source, $claimed->attachment_ids ?? []);
                $transportAttachments = $attachmentSelector->toTransportPayload($selected);
            } catch (\Throwable $exception) {
                $code = property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'attachment_unsafe';
                $this->markFailed($claimed, $code, 'Attachment revalidation failed before transport submission.', $audit);

                return;
            }
        }

        $payload = new OutboundMessageData(
            messageId: (string) $claimed->getKey(),
            fromAddress: $claimed->from_address,
            fromDisplayName: $claimed->from_display_name,
            to: $claimed->to_recipients ?? [],
            cc: $claimed->cc_recipients ?? [],
            bcc: $claimed->bcc_recipients ?? [],
            subject: (string) ($claimed->subject ?? ''),
            textBody: $claimed->text_body,
            htmlBody: $claimed->html_body,
            inReplyTo: $claimed->in_reply_to,
            references: $claimed->references,
            attachments: $transportAttachments,
        );

        $result = $transport->send($payload);

        if ($result->result === OutboundTransportResult::Accepted) {
            $this->markSent($claimed, $result->provider, $result->providerMessageId, $audit);

            return;
        }

        if ($result->result === OutboundTransportResult::TemporaryFailure) {
            $maxAttempts = max(1, (int) config('outbound.send_max_attempts', 3));
            if ($claimed->attempt_count < $maxAttempts) {
                OutboundMessage::query()
                    ->whereKey($claimed->getKey())
                    ->where('state', OutboundMessageState::Sending->value)
                    ->update([
                        'state' => OutboundMessageState::Queued->value,
                        'failure_code' => $result->failureCode,
                        'failure_message' => $result->failureMessage,
                        'provider' => $result->provider,
                        'updated_at' => now(),
                    ]);

                $audit->write(
                    'outbound.retry_scheduled',
                    (string) $claimed->user_id,
                    $claimed->fresh(),
                    null,
                    ['state' => OutboundMessageState::Queued->value],
                    [
                        'attempt' => $claimed->attempt_count,
                        'failure_code' => $result->failureCode,
                        'provider' => $result->provider,
                    ],
                );

                throw new \RuntimeException('Outbound temporary transport failure; retrying.');
            }

            $audit->write(
                'outbound.retry_exhausted',
                (string) $claimed->user_id,
                $claimed,
                null,
                ['state' => OutboundMessageState::Failed->value],
                [
                    'attempt' => $claimed->attempt_count,
                    'failure_code' => $result->failureCode,
                    'provider' => $result->provider,
                ],
            );
        }

        $this->markFailed(
            $claimed,
            $result->failureCode ?? 'transport_error',
            $result->failureMessage ?? 'Transport submission failed.',
            $audit,
            $result->provider,
        );
    }

    private function markSent(
        OutboundMessage $message,
        ?string $provider,
        ?string $providerMessageId,
        AuditLogWriter $audit,
    ): void {
        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sending->value)
            ->update([
                'state' => OutboundMessageState::Sent->value,
                'sent_at' => now(),
                'provider' => $provider,
                'provider_message_id' => $providerMessageId,
                'failure_code' => null,
                'failure_message' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return;
        }

        $fresh = $message->fresh();
        $audit->write(
            $this->auditAction($fresh->operation, 'sent'),
            (string) $fresh->user_id,
            $fresh,
            ['state' => OutboundMessageState::Sending->value],
            ['state' => OutboundMessageState::Sent->value],
            [
                'attempt' => $fresh->attempt_count,
                'provider' => $provider,
                'operation' => $fresh->operation->value,
                'inbox_id' => (string) $fresh->inbox_id,
                'recipient_count' => $fresh->recipientCount(),
            ],
        );
    }

    private function markFailed(
        OutboundMessage $message,
        string $failureCode,
        string $failureMessage,
        AuditLogWriter $audit,
        ?string $provider = null,
    ): void {
        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sending->value)
            ->update([
                'state' => OutboundMessageState::Failed->value,
                'failed_at' => now(),
                'failure_code' => $failureCode,
                'failure_message' => mb_substr($failureMessage, 0, 255),
                'provider' => $provider ?? $message->provider,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return;
        }

        $fresh = $message->fresh();
        $audit->write(
            $this->auditAction($fresh->operation, 'failed'),
            (string) $fresh->user_id,
            $fresh,
            ['state' => OutboundMessageState::Sending->value],
            ['state' => OutboundMessageState::Failed->value],
            [
                'attempt' => $fresh->attempt_count,
                'failure_code' => $failureCode,
                'provider' => $fresh->provider,
                'operation' => $fresh->operation->value,
                'inbox_id' => (string) $fresh->inbox_id,
                'recipient_count' => $fresh->recipientCount(),
            ],
        );
    }

    private function auditAction(OutboundOperation $operation, string $suffix): string
    {
        return match ($operation) {
            OutboundOperation::Reply => 'outbound.reply_'.$suffix,
            OutboundOperation::Forward => 'outbound.forward_'.$suffix,
            default => 'outbound.message_'.$suffix,
        };
    }
}
