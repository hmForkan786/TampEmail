<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\DTOs\Outbound\CreateOutboundRetentionHoldData;
use App\Enums\OutboundRetentionHoldReasonCode;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundPruneService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin-only legal/security hold on a single outbound message.
 *
 * Blocks every {@see OutboundPruneService} category
 * for the message (content redaction, attempt/event pruning, hard delete)
 * until released. Never restores user visibility for a message the owner
 * has already hidden via {@see DeleteOutboundMessageAction}.
 */
final class CreateOutboundRetentionHoldAction
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function execute(CreateOutboundRetentionHoldData $data): OutboundMessage
    {
        return DB::transaction(function () use ($data): OutboundMessage {
            $actor = User::query()->whereKey($data->heldByUserId)->lockForUpdate()->first();

            if (! $actor?->isPlatformAdmin()) {
                throw new AuthorizationException('Only an active platform admin may set an outbound retention hold.');
            }

            if (OutboundRetentionHoldReasonCode::tryFrom($data->reasonCode) === null) {
                throw new InvalidArgumentException('Invalid outbound retention hold reason code.');
            }

            $message = OutboundMessage::query()->whereKey($data->messageId)->lockForUpdate()->first();

            if ($message === null) {
                throw new InvalidArgumentException('Outbound message does not exist.');
            }

            $message->forceFill([
                'retention_hold_until' => $data->heldUntil,
                'retention_hold_reason_code' => $data->reasonCode,
            ])->save();

            $fresh = $message->fresh();

            $this->audit->write(
                'outbound.retention_hold_set',
                (string) $actor->id,
                $fresh,
                null,
                [
                    'retention_hold_until' => $fresh->retention_hold_until?->toIso8601String(),
                    'retention_hold_reason_code' => $fresh->retention_hold_reason_code,
                ],
                [
                    'message_id' => (string) $fresh->id,
                    'reason_code' => $data->reasonCode,
                    'held_until' => $data->heldUntil?->toIso8601String(),
                ],
            );

            return $fresh;
        });
    }
}
