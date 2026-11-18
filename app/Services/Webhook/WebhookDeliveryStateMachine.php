<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\WebhookDelivery;
use InvalidArgumentException;

/** Deterministic webhook delivery lifecycle transitions. */
final class WebhookDeliveryStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending' => ['queued', 'cancelled'],
        'queued' => ['delivering', 'cancelled'],
        'delivering' => ['delivered', 'retry_scheduled', 'failed'],
        'retry_scheduled' => ['queued', 'cancelled'],
        'delivered' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @param  array<string, mixed>  $attributes */
    public function transition(WebhookDelivery $delivery, string $to, array $attributes = []): WebhookDelivery
    {
        $from = $delivery->status;
        if (! $this->canTransition($from, $to)) {
            throw new InvalidArgumentException("Invalid webhook delivery transition [{$from}] -> [{$to}].");
        }

        $delivery->forceFill(array_merge(['status' => $to], $attributes))->save();

        return $delivery->refresh();
    }

    public function isTerminal(string $status): bool
    {
        return in_array($status, ['delivered', 'failed', 'cancelled'], true);
    }
}
