<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\AuditLog;
use App\Models\OutboundAbuseBlock;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Atomic outbound abuse / quota enforcement shared by API and UI create paths.
 */
final class OutboundRateLimiter
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * @param  list<string>  $recipients
     */
    public function assertWithinLimits(User $user, array $recipients = [], int $attachmentBytes = 0): void
    {
        try {
            DB::transaction(function () use ($user, $recipients, $attachmentBytes): void {
                User::query()->whereKey($user->getKey())->lockForUpdate()->first();
                $this->assertNotBlocked($user);
                $this->assertMessageWindows($user);
                $this->assertRecipientWindows($user, $recipients);
                $this->assertConcurrentQueued($user);
                $this->assertOutboundBytes($user, $attachmentBytes);
                $this->assertAbuseSignals($user);
            });
        } catch (OutboundSendException $exception) {
            throw $exception;
        } catch (\Throwable) {
            if ((bool) config('outbound.abuse.fail_closed_on_quota_backend', true)) {
                Cache::increment('outbound.metrics.quota_backend_failures');
                throw new OutboundSendException('quota_backend_unavailable', 'Outbound quota enforcement is temporarily unavailable.', 503);
            }
        }
    }

    public function assertNotBlocked(User $user): void
    {
        $block = OutboundAbuseBlock::query()
            ->where('user_id', $user->getKey())
            ->whereNull('cleared_at')
            ->whereIn('state', ['temporarily_blocked', 'suspended'])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('started_at')
            ->first();

        if ($block === null) {
            return;
        }

        $retryAfter = $block->expires_at?->diffInSeconds(now()) ?? 3600;
        $code = $block->state === 'suspended' ? 'outbound_suspended' : 'temporarily_blocked';

        throw new OutboundSendException(
            $code,
            $block->state === 'suspended'
                ? 'Outbound sending is suspended for this account.'
                : 'Outbound sending is temporarily blocked.',
            $block->state === 'suspended' ? 403 : 429,
        );
    }

    public function applyTemporaryBlock(
        User $user,
        string $reasonCode,
        string $source = 'system',
        ?User $actor = null,
        ?\DateTimeInterface $expiresAt = null,
        string $state = 'temporarily_blocked',
    ): OutboundAbuseBlock {
        $block = OutboundAbuseBlock::query()->create([
            'user_id' => $user->getKey(),
            'state' => $state,
            'reason_code' => $reasonCode,
            'source' => $source,
            'actor_user_id' => $actor?->getKey(),
            'started_at' => now(),
            'expires_at' => $expiresAt ?? now()->addHours((int) config('outbound.abuse.temp_block_hours', 24)),
        ]);

        $this->audit->write(
            'outbound.abuse_block_applied',
            $actor !== null ? (string) $actor->getKey() : null,
            $block,
            null,
            ['state' => $state],
            [
                'user_id' => (string) $user->getKey(),
                'reason_code' => $reasonCode,
                'source' => $source,
                'expires_at' => $block->expires_at?->toIso8601String(),
            ],
        );

        return $block;
    }

    public function clearBlock(OutboundAbuseBlock $block, User $actor): OutboundAbuseBlock
    {
        $block->forceFill([
            'cleared_at' => now(),
        ])->save();

        $this->audit->write(
            'outbound.abuse_block_cleared',
            (string) $actor->getKey(),
            $block,
            ['state' => $block->state],
            ['cleared' => true],
            [
                'user_id' => (string) $block->user_id,
                'reason_code' => $block->reason_code,
            ],
        );

        return $block->fresh();
    }

    private function assertMessageWindows(User $user): void
    {
        $checks = [
            'messages_per_minute' => [now()->subMinute(), (int) config('outbound.messages_per_minute', 5), 'rate_limit_minute'],
            'messages_per_hour' => [now()->subHour(), (int) config('outbound.messages_per_hour', 30), 'rate_limit_hour'],
            'messages_per_day' => [now()->subDay(), (int) config('outbound.messages_per_day', 200), 'rate_limit_day'],
        ];

        foreach ($checks as [$since, $limit, $code]) {
            if ($limit <= 0) {
                continue;
            }
            $count = OutboundMessage::query()
                ->where('user_id', $user->getKey())
                ->where('created_at', '>=', $since)
                ->whereNotIn('state', [OutboundMessageState::Cancelled->value, OutboundMessageState::Draft->value])
                ->count();
            if ($count >= $limit) {
                Cache::increment('outbound.metrics.throttled_requests');
                throw new OutboundSendException($code, 'Outbound message limit exceeded. Please try again later.', 429);
            }
        }
    }

    /**
     * @param  list<string>  $recipients
     */
    private function assertRecipientWindows(User $user, array $recipients): void
    {
        $unique = array_values(array_unique(array_map(static fn (string $email): string => strtolower(trim($email)), $recipients)));
        $perMessage = (int) config('outbound.max_recipients_per_message', 20);
        if ($perMessage > 0 && count($unique) > $perMessage) {
            throw new OutboundSendException('recipients_limit', "A message may include at most {$perMessage} recipients.", 422);
        }

        if ($unique === []) {
            return;
        }

        $this->assertUniqueRecipientWindow($user, $unique, now()->subHour(), (int) config('outbound.unique_recipients_per_hour', 100), 'unique_recipients_hour');
        $this->assertUniqueRecipientWindow($user, $unique, now()->subDay(), (int) config('outbound.unique_recipients_per_day', 500), 'unique_recipients_day');
    }

    /**
     * @param  list<string>  $unique
     */
    private function assertUniqueRecipientWindow(User $user, array $unique, \DateTimeInterface $since, int $limit, string $code): void
    {
        if ($limit <= 0) {
            return;
        }

        $recent = OutboundMessage::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $since)
            ->whereNotIn('state', [OutboundMessageState::Cancelled->value])
            ->get(['to_recipients', 'cc_recipients', 'bcc_recipients']);

        $seen = [];
        foreach ($recent as $message) {
            foreach ([...($message->to_recipients ?? []), ...($message->cc_recipients ?? []), ...($message->bcc_recipients ?? [])] as $address) {
                $seen[strtolower((string) $address)] = true;
            }
        }

        foreach ($unique as $address) {
            $seen[$address] = true;
        }

        if (count($seen) > $limit) {
            Cache::increment('outbound.metrics.unique_recipient_spikes');
            throw new OutboundSendException($code, 'Unique recipient limit exceeded. Please try again later.', 429);
        }
    }

    private function assertConcurrentQueued(User $user): void
    {
        $limit = (int) config('outbound.concurrent_queued_messages', 20);
        if ($limit <= 0) {
            return;
        }

        $count = OutboundMessage::query()
            ->where('user_id', $user->getKey())
            ->whereIn('state', [OutboundMessageState::Queued->value, OutboundMessageState::Sending->value])
            ->count();

        if ($count >= $limit) {
            throw new OutboundSendException('concurrent_queued_limit', 'Too many outbound messages are currently queued.', 429);
        }
    }

    private function assertOutboundBytes(User $user, int $attachmentBytes): void
    {
        $limit = (int) config('outbound.outbound_bytes_per_day', 104857600);
        if ($limit <= 0) {
            return;
        }

        $used = (int) OutboundMessage::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->whereNotIn('state', [OutboundMessageState::Cancelled->value])
            ->sum(DB::raw('COALESCE(LENGTH(text_body),0) + COALESCE(LENGTH(html_body),0)'));

        if (($used + max(0, $attachmentBytes)) > $limit) {
            throw new OutboundSendException('outbound_bytes_day', 'Daily outbound size limit exceeded.', 429);
        }
    }

    private function assertAbuseSignals(User $user): void
    {
        $bounceThreshold = (int) config('outbound.abuse.bounce_threshold_24h', 10);
        $complaintThreshold = (int) config('outbound.abuse.complaint_threshold_24h', 2);
        $failedThreshold = (int) config('outbound.abuse.failed_send_threshold_24h', 25);

        $bounces = AuditLog::query()
            ->where('action', 'outbound.bounce_received')
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $complaints = AuditLog::query()
            ->where('action', 'outbound.complaint_received')
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $failed = OutboundMessage::query()
            ->where('user_id', $user->getKey())
            ->where('state', OutboundMessageState::Failed->value)
            ->where('failed_at', '>=', now()->subDay())
            ->count();
        $suppressionBlocks = AuditLog::query()
            ->where('action', 'outbound.send_blocked_by_suppression')
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($complaints >= $complaintThreshold && $complaintThreshold > 0) {
            Cache::increment('outbound.metrics.high_complaint_accounts');
            $this->applyTemporaryBlock($user, 'complaint_threshold', 'system');
            throw new OutboundSendException('temporarily_blocked', 'Outbound sending is temporarily blocked.', 429);
        }

        if ($bounces >= $bounceThreshold && $bounceThreshold > 0) {
            Cache::increment('outbound.metrics.high_bounce_accounts');
            $this->applyTemporaryBlock($user, 'bounce_threshold', 'system');
            throw new OutboundSendException('temporarily_blocked', 'Outbound sending is temporarily blocked.', 429);
        }

        if ($failed >= $failedThreshold && $failedThreshold > 0) {
            $this->applyTemporaryBlock($user, 'failed_send_threshold', 'system');
            throw new OutboundSendException('temporarily_blocked', 'Outbound sending is temporarily blocked.', 429);
        }

        if ($suppressionBlocks >= (int) config('outbound.abuse.suppression_block_threshold_24h', 20)) {
            $this->applyTemporaryBlock($user, 'suppression_block_spike', 'system');
            throw new OutboundSendException('temporarily_blocked', 'Outbound sending is temporarily blocked.', 429);
        }
    }
}
