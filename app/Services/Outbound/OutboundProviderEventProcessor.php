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

            $event->forceFill(['processed_at' => now(), 'outcome' => $outcome])->save();

            return [
                'event' => $event->fresh(),
                'duplicate' => false,
                'outcome' => $outcome,
            ];
        });
    }

    /**
     * Retries correlation for previously unmatched events within a bounded
     * recent window. Covers the race where a provider webhook arrives before
     * the delivery job persists `provider_message_id`. Never re-evaluates
     * already-matched events and never widens beyond the configured window.
     *
     * @return array{evaluated: int, matched: int}
     */
    public function reconcileUnmatched(?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('outbound.reconciliation.unmatched_event_batch_size', 50));
        $windowHours = max(1, (int) config('outbound.reconciliation.unmatched_event_window_hours', 24));
        $since = now()->subHours($windowHours);

        $summary = ['evaluated' => 0, 'matched' => 0];

        $eventIds = OutboundProviderEvent::query()
            ->whereNull('outbound_message_id')
            ->where('received_at', '>=', $since)
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            $summary['evaluated']++;
            if ($this->reconcileUnmatchedOne((string) $eventId)) {
                $summary['matched']++;
            }
        }

        return $summary;
    }

    private function reconcileUnmatchedOne(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = OutboundProviderEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null || $event->outbound_message_id !== null) {
                return false;
            }

            $data = new OutboundProviderEventData(
                provider: $event->provider,
                providerEventId: $event->provider_event_id,
                providerMessageId: $event->provider_message_id,
                eventType: $event->event_type,
                providerEventAt: $event->provider_event_at ?? $event->received_at,
            );

            $message = $this->resolveMessage($data);
            if ($message === null) {
                return false;
            }

            $event->forceFill(['outbound_message_id' => $message->getKey()])->save();
            $outcome = $this->applyTransition($event, $message, $data);
            $event->forceFill(['outcome' => $outcome])->save();

            $this->audit->write(
                'outbound.provider_event_reconciled',
                (string) $message->user_id,
                $event,
                null,
                null,
                [
                    'provider' => $data->provider,
                    'event_type' => $data->eventType->value,
                    'provider_event_id_hash' => hash('sha256', $data->providerEventId),
                    'outcome' => $outcome,
                ],
            );

            return true;
        });
    }

    /**
     * Retries the transition for events that were already matched to a
     * message but ignored because the message had not yet reached the
     * expected state (e.g. a `delivered` webhook arriving while the
     * delivery job's `sent` update has not yet committed). Bounded by
     * both a recent window and a per-event attempt cap so a permanently
     * inapplicable event (e.g. the message was legitimately cancelled)
     * cannot be retried forever. Never re-applies an already-terminal
     * outcome — {@see applyTransition} itself is idempotent per message
     * state, so replays are always safe.
     *
     * @return array{evaluated: int, resolved: int}
     */
    public function reconcileOutOfOrder(?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('outbound.reconciliation.out_of_order_batch_size', 50));
        $windowHours = max(1, (int) config('outbound.reconciliation.out_of_order_window_hours', 24));
        $maxAttempts = max(1, (int) config('outbound.reconciliation.out_of_order_max_attempts', 10));
        $since = now()->subHours($windowHours);

        $summary = ['evaluated' => 0, 'resolved' => 0];

        $eventIds = OutboundProviderEvent::query()
            ->where('outcome', 'ignored_state')
            ->whereNotNull('outbound_message_id')
            ->where('reconciliation_attempts', '<', $maxAttempts)
            ->where('received_at', '>=', $since)
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            $summary['evaluated']++;
            if ($this->reconcileOutOfOrderOne((string) $eventId)) {
                $summary['resolved']++;
            }
        }

        return $summary;
    }

    private function reconcileOutOfOrderOne(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = OutboundProviderEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null || $event->outcome !== 'ignored_state' || $event->outbound_message_id === null) {
                return false;
            }

            $message = OutboundMessage::query()->whereKey($event->outbound_message_id)->lockForUpdate()->first();
            if ($message === null) {
                $event->forceFill(['reconciliation_attempts' => $event->reconciliation_attempts + 1])->save();

                return false;
            }

            $data = new OutboundProviderEventData(
                provider: $event->provider,
                providerEventId: $event->provider_event_id,
                providerMessageId: $event->provider_message_id,
                eventType: $event->event_type,
                providerEventAt: $event->provider_event_at ?? $event->received_at,
            );

            $outcome = $this->applyTransition($event, $message, $data);

            $event->forceFill([
                'outcome' => $outcome,
                'reconciliation_attempts' => $event->reconciliation_attempts + 1,
            ])->save();

            if ($outcome === 'ignored_state') {
                return false;
            }

            $this->audit->write(
                'outbound.provider_event_out_of_order_resolved',
                (string) $message->user_id,
                $event,
                null,
                null,
                [
                    'provider' => $data->provider,
                    'event_type' => $data->eventType->value,
                    'provider_event_id_hash' => hash('sha256', $data->providerEventId),
                    'outcome' => $outcome,
                ],
            );

            return true;
        });
    }

    /**
     * Marks unmatched events terminal once they age out of the correlation
     * window so reconciliation stops scanning them. Terminal events are
     * never deleted — they remain visible to ops/admins as evidence of an
     * event that never found its message (e.g. dropped submission, id
     * mismatch), which is itself an operational signal worth keeping.
     *
     * @return array{evaluated: int, terminal: int}
     */
    public function finalizeExpiredUnmatched(?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('outbound.reconciliation.unmatched_event_batch_size', 50));
        $windowHours = max(1, (int) config('outbound.reconciliation.unmatched_event_window_hours', 24));
        $cutoff = now()->subHours($windowHours);

        $summary = ['evaluated' => 0, 'terminal' => 0];

        $eventIds = OutboundProviderEvent::query()
            ->whereNull('outbound_message_id')
            ->whereNull('terminal_unmatched_at')
            ->where('received_at', '<', $cutoff)
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            $summary['evaluated']++;
            if ($this->finalizeExpiredUnmatchedOne((string) $eventId)) {
                $summary['terminal']++;
            }
        }

        return $summary;
    }

    private function finalizeExpiredUnmatchedOne(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = OutboundProviderEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null || $event->outbound_message_id !== null || $event->terminal_unmatched_at !== null) {
                return false;
            }

            $event->forceFill(['terminal_unmatched_at' => now()])->save();

            Cache::increment('outbound.metrics.terminal_unmatched_events');

            $this->audit->write(
                'outbound.provider_event_terminal_unmatched',
                null,
                $event,
                null,
                null,
                [
                    'provider' => $event->provider,
                    'event_type' => $event->event_type->value,
                    'provider_event_id_hash' => hash('sha256', $event->provider_event_id),
                ],
            );

            return true;
        });
    }

    private function resolveMessage(OutboundProviderEventData $data): ?OutboundMessage
    {
        if ($data->providerMessageId === null || $data->providerMessageId === '') {
            return null;
        }

        $candidates = $this->messageIdCandidates($data->providerMessageId);
        if ($candidates === []) {
            return null;
        }

        $aliases = config("outbound.delivery_webhook.providers.{$data->provider}.transport_aliases", []);
        $aliases = is_array($aliases) ? array_values(array_filter(array_map('strval', $aliases))) : [];

        $matches = OutboundMessage::query()
            ->whereIn('provider_message_id', $candidates)
            ->when(
                $data->provider !== 'generic' && $aliases === [],
                fn ($query) => $query->where('provider', $data->provider),
            )
            ->when(
                $aliases !== [],
                fn ($query) => $query->where(function ($inner) use ($data, $aliases): void {
                    $inner->where('provider', $data->provider)
                        ->orWhereIn('provider', $aliases);
                }),
            )
            ->lockForUpdate()
            ->get();

        // Ambiguous matches must not mutate state.
        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    /**
     * @return list<string>
     */
    private function messageIdCandidates(string $providerMessageId): array
    {
        $trimmed = trim($providerMessageId);
        if ($trimmed === '') {
            return [];
        }

        $withBrackets = $trimmed;
        if (! str_starts_with($withBrackets, '<')) {
            $withBrackets = '<'.$withBrackets;
        }
        if (! str_ends_with($withBrackets, '>')) {
            $withBrackets .= '>';
        }

        $without = trim($trimmed, "<> \t");

        return array_values(array_unique(array_filter([
            mb_substr($trimmed, 0, 255),
            mb_substr($withBrackets, 0, 255),
            $without !== '' ? mb_substr($without, 0, 255) : null,
            $without !== '' ? mb_substr('<'.$without.'>', 0, 255) : null,
        ])));
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
