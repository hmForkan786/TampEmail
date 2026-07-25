<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use Illuminate\Support\Facades\DB;

final class RetryOutboundMessageAction
{
    public function __construct(
        private readonly OutboundAuthorizationService $authorization,
        private readonly OutboundAttachmentSelector $attachments,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    public function execute(string $messageId, User $user, ?string $apiKeyId = null): OutboundMessage
    {
        $message = DB::transaction(function () use ($messageId, $user, $apiKeyId): OutboundMessage {
            $message = OutboundMessage::query()
                ->with(['inbox.domain', 'sourceEmail'])
                ->whereKey($messageId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                throw new OutboundSendException('not_found', 'Outbound message not found.', 404);
            }

            if ($message->state !== OutboundMessageState::Failed) {
                throw new OutboundSendException('retry_not_allowed', 'Only failed messages can be retried.', 422);
            }

            $nonRetryable = ['attachment_unsafe', 'attachment_not_found', 'attachment_deleted', 'attachment_unavailable', 'user_inactive', 'inbox_inactive', 'domain_outbound_disabled', 'entitlement_denied', 'invalid_config'];
            if (in_array((string) $message->failure_code, $nonRetryable, true)) {
                throw new OutboundSendException('retry_not_allowed', 'This failure category cannot be retried.', 422);
            }

            $this->authorization->assertCanSend($user, $message->inbox, $message->operation);

            if ($message->operation === OutboundOperation::Forward && ($message->attachment_ids ?? []) !== []) {
                if ($message->sourceEmail === null) {
                    throw new OutboundSendException('email_not_found', 'The original email was not found.', 404);
                }
                $this->attachments->selectForForward($message->sourceEmail, $message->attachment_ids ?? []);
            }

            $updated = OutboundMessage::query()
                ->whereKey($message->getKey())
                ->where('state', OutboundMessageState::Failed->value)
                ->update([
                    'state' => OutboundMessageState::Queued->value,
                    'queued_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'failure_message' => null,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new OutboundSendException('retry_not_allowed', 'Only failed messages can be retried.', 422);
            }

            $fresh = $message->fresh();
            $this->auditLogWriter->write(
                'outbound.manual_retry_requested',
                (string) $user->getKey(),
                $fresh,
                ['state' => OutboundMessageState::Failed->value],
                ['state' => OutboundMessageState::Queued->value],
                [
                    'operation' => $fresh->operation->value,
                    'inbox_id' => (string) $fresh->inbox_id,
                    'api_key_id' => $apiKeyId,
                    'attempt' => $fresh->attempt_count,
                ],
            );

            return $fresh;
        });

        DeliverOutboundMessageJob::dispatch((string) $message->getKey());

        return $message;
    }
}
