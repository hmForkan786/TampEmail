<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Safe, normalized timeline event types surfaced to users and admins.
 *
 * The timeline is a read model built from existing sources (audit log
 * entries already written by the send/reply/forward, delivery, provider
 * event, and reconciliation code paths) — it is never a second source of
 * truth and never stores raw provider payloads, bodies, or recipients.
 */
enum OutboundTimelineEventType: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Sending = 'sending';
    case RetryScheduled = 'retry_scheduled';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Delayed = 'delayed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case ManualRetry = 'manual_retry';
    case ScheduleCreated = 'schedule_created';
    case ScheduleUpdated = 'schedule_updated';
    case ScheduleCancelled = 'schedule_cancelled';
    case AdminAction = 'admin_action';

    /**
     * Event types visible on the user-facing timeline endpoint. Bounced and
     * complained are folded into `failed`/omitted respectively so bounce
     * diagnostics and complaint metadata are never surfaced to end users.
     *
     * @return list<self>
     */
    public static function userVisible(): array
    {
        return [
            self::Created,
            self::Queued,
            self::Sending,
            self::RetryScheduled,
            self::Sent,
            self::Delivered,
            self::Delayed,
            self::Failed,
            self::Cancelled,
            self::ManualRetry,
            self::ScheduleCreated,
            self::ScheduleUpdated,
            self::ScheduleCancelled,
        ];
    }
}
