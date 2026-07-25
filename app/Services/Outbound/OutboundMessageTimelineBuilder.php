<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundTimelineEventType;
use App\Models\AuditLog;
use App\Models\OutboundMessage;

/**
 * Builds a safe timeline read model for one outbound message from the
 * audit log rows already written by the send/reply/forward, delivery,
 * provider-event, and reconciliation code paths.
 *
 * This is intentionally a read model, not a second source of truth: it
 * never stores its own events and never duplicates the underlying audit
 * trail. It only maps a curated allow-list of safe audit actions
 * (never a raw dump of every audit row) onto normalized timeline entries,
 * and applies stricter redaction for the user-facing view.
 *
 * Never includes raw provider payloads, secrets, message bodies, BCC, or
 * full recipient lists — those never appear in the underlying audit
 * metadata for outbound actions in the first place.
 */
final class OutboundMessageTimelineBuilder
{
    public function __construct(
        private readonly OutboundFailureCategoryMapper $categories,
    ) {}

    /**
     * Audit actions that are excluded from the timeline entirely because
     * they either duplicate a more specific transition already listed
     * below (`retry_exhausted` immediately precedes `*_failed`;
     * `provider_event_reconciled` duplicates the delivered/failed entry it
     * caused) or never touch this message directly.
     */
    private const EXCLUDED_ACTIONS = [
        'outbound.retry_exhausted',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function build(OutboundMessage $message, bool $admin = false): array
    {
        $logs = AuditLog::query()
            ->where('auditable_type', OutboundMessage::class)
            ->where('auditable_id', (string) $message->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $entries = [];
        foreach ($logs as $log) {
            $entry = $this->mapLog($log, $admin);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if (! $admin) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => in_array($entry['type'], array_map(
                    static fn (OutboundTimelineEventType $type): string => $type->value,
                    OutboundTimelineEventType::userVisible(),
                ), true),
            ));
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapLog(AuditLog $log, bool $admin): ?array
    {
        if (in_array($log->action, self::EXCLUDED_ACTIONS, true)) {
            return null;
        }

        $metadata = is_array($log->metadata) ? $log->metadata : [];

        $mapping = $this->actionMapping($log->action, $metadata, $admin);
        if ($mapping === null) {
            return null;
        }

        [$type, $label, $actorType] = $mapping;

        $failureCode = is_string($metadata['failure_code'] ?? null) ? $metadata['failure_code'] : null;
        $category = $failureCode !== null
            ? ($admin ? $this->categories->categorize($failureCode) : $this->categories->userSafeCategory($failureCode))
            : null;

        $entry = [
            'type' => $type->value,
            'label' => $label,
            'occurred_at' => $log->created_at?->toIso8601String(),
            'actor_type' => $actorType,
            'category' => $category,
            'attempt_number' => isset($metadata['attempt']) ? (int) $metadata['attempt'] : null,
        ];

        if ($admin) {
            $entry['provider'] = is_string($metadata['provider'] ?? null) ? $metadata['provider'] : null;
            $entry['failure_code'] = $failureCode;
            $entry['audit_action'] = $log->action;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: OutboundTimelineEventType, 1: string, 2: string}|null
     */
    private function actionMapping(string $action, array $metadata, bool $admin): ?array
    {
        return match ($action) {
            'outbound.message_created', 'outbound.reply_created', 'outbound.forward_created' => [OutboundTimelineEventType::Created, 'Message created', 'user'],
            'outbound.message_queued', 'outbound.reply_queued', 'outbound.forward_queued' => [OutboundTimelineEventType::Queued, 'Queued for delivery', 'user'],
            'outbound.message_sending' => [OutboundTimelineEventType::Sending, 'Delivery attempt started', 'system'],
            'outbound.retry_scheduled', 'outbound.stale_sending_requeued' => [OutboundTimelineEventType::RetryScheduled, 'Retry scheduled', 'system'],
            'outbound.message_sent', 'outbound.reply_sent', 'outbound.forward_sent' => [OutboundTimelineEventType::Sent, $admin ? 'Provider accepted (sent)' : 'Sent', 'system'],
            'outbound.delivery_confirmed' => [OutboundTimelineEventType::Delivered, 'Delivered', 'provider'],
            'outbound.stale_sending_flagged_ambiguous' => [OutboundTimelineEventType::Delayed, 'Delivery status delayed pending verification', 'system'],
            'outbound.provider_event_received' => $this->providerEventReceivedMapping($metadata),
            // A bounce is user-visible as a generic "failed" outcome (the
            // dedicated Bounced type is admin-only detail); it must never be
            // silently hidden from the user because Bounced isn't in
            // OutboundTimelineEventType::userVisible().
            'outbound.bounce_received' => $admin
                ? [OutboundTimelineEventType::Bounced, 'Delivery bounced', 'provider']
                : [OutboundTimelineEventType::Failed, 'Delivery failed', 'provider'],
            'outbound.delivery_failed' => [OutboundTimelineEventType::Failed, 'Delivery failed', 'provider'],
            'outbound.complaint_received' => [OutboundTimelineEventType::Complained, 'Recipient complaint received', 'provider'],
            'outbound.message_failed', 'outbound.reply_failed', 'outbound.forward_failed',
            'outbound.stale_sending_failed_exhausted' => [OutboundTimelineEventType::Failed, 'Delivery failed', 'system'],
            'outbound.message_cancelled' => [OutboundTimelineEventType::Cancelled, 'Cancelled', 'user'],
            'outbound.manual_retry_requested' => [OutboundTimelineEventType::ManualRetry, 'Manual retry requested', 'user'],
            default => null,
        };
    }

    /**
     * `provider_event_received` fires for every provider webhook, including
     * ones that never mutate state (e.g. a bare "accepted" echo). Only the
     * `temporary_failure` case (provider-signaled delay, e.g. SES
     * `DeliveryDelay`) is timeline-worthy and not already covered by a more
     * specific action above.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{0: OutboundTimelineEventType, 1: string, 2: string}|null
     */
    private function providerEventReceivedMapping(array $metadata): ?array
    {
        if (($metadata['event_type'] ?? null) !== 'temporary_failure' || ($metadata['matched'] ?? false) !== true) {
            return null;
        }

        return [OutboundTimelineEventType::Delayed, 'Provider reported a temporary delay', 'provider'];
    }
}
