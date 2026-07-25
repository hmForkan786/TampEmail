<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundDeliveryAttemptState;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;

/**
 * Appends one row per outbound delivery attempt instead of overwriting a
 * single set of columns, so attempt history survives retries.
 *
 * Never persists body, full recipient lists, raw SMTP/provider responses,
 * credentials, or attachment content — only ids, coarse safe categories,
 * timestamps, and durations.
 */
final class OutboundDeliveryAttemptRecorder
{
    public function __construct(
        private readonly OutboundFailureCategoryMapper $categories,
    ) {}

    /**
     * Record the start of a new attempt. Idempotent: replaying the same
     * (message, attempt_number) pair never creates a duplicate row.
     */
    public function start(OutboundMessage $message, ?string $transport): OutboundDeliveryAttempt
    {
        return OutboundDeliveryAttempt::query()->firstOrCreate(
            [
                'outbound_message_id' => $message->getKey(),
                'attempt_number' => $message->attempt_count,
            ],
            [
                'transport' => $transport,
                'state' => OutboundDeliveryAttemptState::Attempted->value,
                'started_at' => now(),
            ],
        );
    }

    /**
     * Record the terminal outcome of the current attempt (attempt_number =
     * $message->attempt_count). No-op when the attempt row is missing or
     * already terminal, so a duplicate transition can never overwrite a
     * previously recorded outcome.
     */
    public function complete(
        OutboundMessage $message,
        OutboundDeliveryAttemptState $state,
        ?string $result = null,
        ?string $failureCode = null,
        ?string $providerMessageId = null,
    ): void {
        $attempt = $this->current($message);
        if ($attempt === null || $attempt->state->isTerminal()) {
            return;
        }

        $completedAt = now();

        $attempt->forceFill([
            'state' => $state->value,
            'result' => $result,
            'failure_category' => $failureCode !== null ? $this->categories->categorize($failureCode) : null,
            'provider_message_id' => $providerMessageId,
            'completed_at' => $completedAt,
            'duration_ms' => $attempt->started_at !== null
                ? max(0, $attempt->started_at->diffInMilliseconds($completedAt))
                : null,
        ])->save();
    }

    /**
     * Mark the current in-flight attempt ambiguous: the transport was
     * invoked but the worker died before the outcome could be persisted.
     * Never overwrites an already-terminal attempt outcome.
     */
    public function markAmbiguous(OutboundMessage $message): void
    {
        $attempt = $this->current($message);
        if ($attempt === null || $attempt->state->isTerminal()) {
            return;
        }

        $attempt->forceFill([
            'state' => OutboundDeliveryAttemptState::Ambiguous->value,
            'ambiguous' => true,
        ])->save();
    }

    private function current(OutboundMessage $message): ?OutboundDeliveryAttempt
    {
        return OutboundDeliveryAttempt::query()
            ->where('outbound_message_id', $message->getKey())
            ->where('attempt_number', $message->attempt_count)
            ->first();
    }
}
