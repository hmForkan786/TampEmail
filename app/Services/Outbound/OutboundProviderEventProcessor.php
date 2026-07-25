<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundProviderEventType;
use App\Models\OutboundMessage;
use App\Models\OutboundProviderEvent;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Persists provider events idempotently and applies deterministic state transitions.
 *
 * Precedence:
 * - duplicate events are idempotent
 * - delivered is not overwritten by older temporary failures
 * - permanent bounce/reject after sent may mark failed when not delivered
 * - complaints are recorded even after delivered
 * - unknown events never mutate message state
 * - cancelled messages never become delivered
 * - unmatched events remain stored for reconciliation
 */
final class OutboundProviderEventProcessor
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly OutboundSuppressionService $suppressions,
    ) {}

    /**
     * @return array{event: OutboundProviderEvent, duplicate: bool, outcome: string}
     */
    public function ingest(OutboundProviderEventData $data, string $signatureState = 'verified'): array
    {
        return DB::transaction(function () use ($data, $signatureState): array {
            $existing = OutboundProviderEvent::query()
                ->where('provider', $data->provider)
                ->where('provider_event_id', $data->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                Cache::increment('outbound.metrics.duplicate_events');

                return [
                    'event' => $existing,
                    'duplicate' => true,
                    'outcome' => 'duplicate',
                ];
            }

            $message = $this->resolveMessage($data);

            $event = OutboundProviderEvent::query()->create([
                'provider' => $data->provider,
                'provider_event_id' => $data->providerEventId,
                'provider_message_id' => $data->providerMessageId,
                'outbound_message_id' => $message?->getKey(),
                'event_type' => $data->eventType,
                'normalized_status' => $data->eventType->value,
                'received_at' => now(),
                'provider_event_at' => $data->providerEventAt,
                'processed_at' => null,
                'signature_state' => $signatureState,
                'metadata' => $this->safeMetadata($data),
            ]);

            $this->audit->write(
                'outbound.provider_event_received',
                $message?->user_id !== null ? (string) $message->user_id : null,
                $message ?? $event,
                null,
                null,
                [
                    'provider' => $data->provider,
                    'event_type' => $data->eventType->value,
                    'provider_event_id_hash' => hash('sha256', $data->providerEventId),
                    'provider_message_id_hash' => $data->providerMessageId !== null
                        ? hash('sha256', $data->providerMessageId)
                        : null,
                    'matched' => $message !== null,
                ],
            );

            $outcome = $this->applyTransition($event, $message, $data);

            $event->forceFill(['processed_at' => now()])->save();

            return [
                'event' => $event->fresh(),
                'duplicate' => false,
                'outcome' => $outcome,
            ];
        });
    }

    private function resolveMessage(OutboundProviderEventData $data): ?OutboundMessage
    {
        if ($data->providerMessageId === null || $data->providerMessageId === '') {
            return null;
        }

        return OutboundMessage::query()
            ->where('provider_message_id', $data->providerMessageId)
            ->when(
                $data->provider !== 'generic',
                fn ($query) => $query->where('provider', $data->provider),
            )
            ->lockForUpdate()
            ->first();
    }

    private function applyTransition(
        OutboundProviderEvent $event,
        ?OutboundMessage $message,
        OutboundProviderEventData $data,
    ): string {
        if ($message === null) {
            $this->audit->write(
                'outbound.provider_event_unmatched',
                null,
                $event,
                null,
                null,
                [
                    'provider' => $data->provider,
                    'event_type' => $data->eventType->value,
                    'provider_event_id_hash' => hash('sha256', $data->providerEventId),
                ],
            );

            return 'unmatched';
        }

        return match ($data->eventType) {
            OutboundProviderEventType::Delivered => $this->markDelivered($message, $data),
            OutboundProviderEventType::PermanentFailure,
            OutboundProviderEventType::Bounced,
            OutboundProviderEventType::Rejected => $this->markFailed($message, $data),
            OutboundProviderEventType::Complained => $this->recordComplaint($message, $data),
            OutboundProviderEventType::Accepted,
            OutboundProviderEventType::TemporaryFailure,
            OutboundProviderEventType::Unknown => 'ignored',
        };
    }

    private function markDelivered(OutboundMessage $message, OutboundProviderEventData $data): string
    {
        if ($message->state === OutboundMessageState::Cancelled) {
            return 'ignored_cancelled';
        }

        if ($message->state === OutboundMessageState::Delivered) {
            return 'already_delivered';
        }

        if ($message->state !== OutboundMessageState::Sent) {
            return 'ignored_state';
        }

        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sent->value)
            ->update([
                'state' => OutboundMessageState::Delivered->value,
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return 'race_ignored';
        }

        $fresh = $message->fresh();
        $this->audit->write(
            'outbound.delivery_confirmed',
            (string) $fresh->user_id,
            $fresh,
            ['state' => OutboundMessageState::Sent->value],
            ['state' => OutboundMessageState::Delivered->value],
            [
                'provider' => $data->provider,
                'event_type' => $data->eventType->value,
                'provider_event_id_hash' => hash('sha256', $data->providerEventId),
            ],
        );

        return 'delivered';
    }

    private function markFailed(OutboundMessage $message, OutboundProviderEventData $data): string
    {
        if ($message->state === OutboundMessageState::Delivered) {
            return 'ignored_after_delivered';
        }

        if ($message->state === OutboundMessageState::Cancelled) {
            return 'ignored_cancelled';
        }

        if ($message->state === OutboundMessageState::Failed) {
            return 'already_failed';
        }

        if ($message->state !== OutboundMessageState::Sent) {
            return 'ignored_state';
        }

        $code = match ($data->eventType) {
            OutboundProviderEventType::Bounced => 'provider_bounce',
            OutboundProviderEventType::Rejected => 'provider_rejected',
            default => 'provider_permanent_failure',
        };

        $updated = OutboundMessage::query()
            ->whereKey($message->getKey())
            ->where('state', OutboundMessageState::Sent->value)
            ->update([
                'state' => OutboundMessageState::Failed->value,
                'failed_at' => now(),
                'failure_code' => $code,
                'failure_message' => 'Provider reported a permanent delivery failure.',
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return 'race_ignored';
        }

        $fresh = $message->fresh();
        $action = $data->eventType === OutboundProviderEventType::Bounced
            ? 'outbound.bounce_received'
            : 'outbound.delivery_failed';

        $this->audit->write(
            $action,
            (string) $fresh->user_id,
            $fresh,
            ['state' => OutboundMessageState::Sent->value],
            ['state' => OutboundMessageState::Failed->value],
            [
                'provider' => $data->provider,
                'event_type' => $data->eventType->value,
                'failure_code' => $code,
                'provider_event_id_hash' => hash('sha256', $data->providerEventId),
            ],
        );

        $this->suppressMessageRecipients(
            $fresh,
            reason: $data->eventType === OutboundProviderEventType::Bounced ? 'permanent_bounce' : 'invalid_recipient',
            provider: $data->provider,
            sourceEventId: null,
        );

        return 'failed';
    }

    private function recordComplaint(OutboundMessage $message, OutboundProviderEventData $data): string
    {
        $this->audit->write(
            'outbound.complaint_received',
            (string) $message->user_id,
            $message,
            null,
            null,
            [
                'provider' => $data->provider,
                'event_type' => $data->eventType->value,
                'message_state' => $message->state->value,
                'provider_event_id_hash' => hash('sha256', $data->providerEventId),
            ],
        );

        $this->suppressMessageRecipients(
            $message,
            reason: 'complaint',
            provider: $data->provider,
            sourceEventId: null,
        );

        return 'complaint_recorded';
    }

    private function suppressMessageRecipients(
        OutboundMessage $message,
        string $reason,
        ?string $provider,
        ?string $sourceEventId,
    ): void {
        $recipients = [
            ...($message->to_recipients ?? []),
            ...($message->cc_recipients ?? []),
            ...($message->bcc_recipients ?? []),
        ];

        foreach (array_unique($recipients) as $recipient) {
            $this->suppressions->suppress(
                email: $recipient,
                reason: $reason,
                source: 'provider_event',
                provider: $provider,
                sourceEventId: $sourceEventId,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeMetadata(OutboundProviderEventData $data): array
    {
        return [
            'reason_code' => $data->metadata['reason_code'] ?? null,
            'provider_event_at' => $data->providerEventAt->toIso8601String(),
        ];
    }
}
