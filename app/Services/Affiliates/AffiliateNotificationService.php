<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Models\User;
use App\Services\Outbound\OutboundNotificationService;

/**
 * Narrow affiliate-facing wrapper around the platform's outbound notification
 * pipeline. Only the closed list of affiliate.* events below is ever emitted;
 * each caller must supply an idempotency key so retried jobs never duplicate
 * a notification. Email delivery is intentionally never forced synchronously
 * here — the underlying service queues email asynchronously when enabled.
 */
final class AffiliateNotificationService
{
    private const EVENTS = [
        'affiliate.application_approved',
        'affiliate.application_rejected',
        'affiliate.commission_earned',
        'affiliate.commission_available',
        'affiliate.commission_reversed',
        'affiliate.withdrawal_approved',
        'affiliate.withdrawal_rejected',
        'affiliate.withdrawal_paid',
        'affiliate.account_suspended',
    ];

    public function __construct(private readonly OutboundNotificationService $outbound) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function notify(User $user, string $event, array $extra = [], ?string $idempotencyKey = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }

        $this->outbound->notify($user, $event, null, $extra, $idempotencyKey);
    }
}
