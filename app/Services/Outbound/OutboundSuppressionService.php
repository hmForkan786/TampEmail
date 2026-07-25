<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;
use App\Models\OutboundRecipientSuppression;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Global recipient suppression with hashed lookup and encrypted reversible display.
 */
final class OutboundSuppressionService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    public function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public function hash(string $email): string
    {
        return hash('sha256', $this->normalize($email));
    }

    public function mask(string $email): string
    {
        $email = $this->normalize($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, 'unknown');
        $localMask = mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1));

        return $localMask.'@'.$domain;
    }

    /**
     * @param  list<string>  $recipients
     */
    public function assertRecipientsAllowed(array $recipients, ?User $actor = null): void
    {
        foreach ($recipients as $recipient) {
            if ($this->isSuppressed($recipient)) {
                if ($actor !== null) {
                    $this->audit->write(
                        'outbound.send_blocked_by_suppression',
                        (string) $actor->getKey(),
                        null,
                        null,
                        null,
                        [
                            'recipient_hash' => $this->hash($recipient),
                            'masked_recipient' => $this->mask($recipient),
                        ],
                    );
                }

                throw new OutboundSendException(
                    'recipient_suppressed',
                    'One or more recipients cannot receive outbound mail.',
                    422,
                );
            }
        }
    }

    public function isSuppressed(string $email): bool
    {
        $hash = $this->hash($email);

        $row = OutboundRecipientSuppression::query()
            ->where('recipient_hash', $hash)
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('suppressed_at')
            ->first();

        return $row !== null;
    }

    public function suppress(
        string $email,
        string $reason,
        string $source,
        ?string $provider = null,
        ?string $sourceEventId = null,
        ?\DateTimeInterface $expiresAt = null,
        string $scopeType = 'global',
        ?string $scopeId = null,
        ?User $actor = null,
    ): OutboundRecipientSuppression {
        $normalized = $this->normalize($email);
        $hash = $this->hash($normalized);

        return DB::transaction(function () use ($normalized, $hash, $reason, $source, $provider, $sourceEventId, $expiresAt, $scopeType, $scopeId, $actor): OutboundRecipientSuppression {
            $existing = OutboundRecipientSuppression::query()
                ->where('recipient_hash', $hash)
                ->where('scope_type', $scopeType)
                ->where(function ($query) use ($scopeId): void {
                    if ($scopeId === null) {
                        $query->whereNull('scope_id');
                    } else {
                        $query->where('scope_id', $scopeId);
                    }
                })
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Unique index includes active=true; deactivate expired actives first when colliding.
            OutboundRecipientSuppression::query()
                ->where('recipient_hash', $hash)
                ->where('scope_type', $scopeType)
                ->where(function ($query) use ($scopeId): void {
                    if ($scopeId === null) {
                        $query->whereNull('scope_id');
                    } else {
                        $query->where('scope_id', $scopeId);
                    }
                })
                ->where('active', true)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->update([
                    'active' => false,
                    'removed_at' => now(),
                    'updated_at' => now(),
                ]);

            $row = OutboundRecipientSuppression::query()->create([
                'recipient_hash' => $hash,
                'recipient_encrypted' => $normalized,
                'masked_recipient' => $this->mask($normalized),
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'reason' => $reason,
                'source' => $source,
                'provider' => $provider,
                'source_event_id' => $sourceEventId,
                'active' => true,
                'suppressed_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $this->audit->write(
                'outbound.recipient_suppressed',
                $actor !== null ? (string) $actor->getKey() : null,
                $row,
                null,
                ['active' => true],
                [
                    'suppression_id' => (string) $row->getKey(),
                    'reason' => $reason,
                    'source' => $source,
                    'scope' => $scopeType,
                    'masked_recipient' => $row->masked_recipient,
                    'recipient_hash' => $hash,
                ],
            );

            return $row;
        });
    }

    public function unsuppress(
        OutboundRecipientSuppression $suppression,
        User $actor,
        bool $elevatedComplaintRemoval = false,
    ): OutboundRecipientSuppression {
        if (! $suppression->active) {
            return $suppression;
        }

        if (in_array($suppression->reason, ['complaint'], true) && ! $elevatedComplaintRemoval) {
            throw new OutboundSendException('suppression_removal_denied', 'Complaint suppressions require elevated authorization.', 403);
        }

        if (in_array($suppression->reason, ['permanent_bounce', 'complaint'], true) && ! $elevatedComplaintRemoval && $suppression->source === 'provider_event') {
            throw new OutboundSendException('suppression_removal_denied', 'Provider suppressions require elevated authorization.', 403);
        }

        $suppression->forceFill([
            'active' => false,
            'removed_at' => now(),
            'removed_by' => $actor->getKey(),
        ])->save();

        $this->audit->write(
            'outbound.recipient_unsuppressed',
            (string) $actor->getKey(),
            $suppression,
            ['active' => true],
            ['active' => false],
            [
                'suppression_id' => (string) $suppression->getKey(),
                'reason' => $suppression->reason,
                'source' => $suppression->source,
                'scope' => $suppression->scope_type,
                'masked_recipient' => $suppression->masked_recipient,
                'recipient_hash' => $suppression->recipient_hash,
            ],
        );

        return $suppression->fresh();
    }
}
