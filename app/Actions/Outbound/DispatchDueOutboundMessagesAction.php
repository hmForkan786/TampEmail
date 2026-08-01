<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundNotificationService;
use App\Services\Outbound\OutboundRateLimiter;
use App\Services\Outbound\OutboundScheduleFieldHelper;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Support\Facades\DB;

final class DispatchDueOutboundMessagesAction
{
    /**
     * @var list<string>
     */
    private const TEMPORARY_DEFER_CODES = [
        'quota_backend_unavailable',
        'outbound_emergency_stop',
        'outbound_rollout_disabled',
        'outbound_rollout_not_canary',
        'outbound_rollout_percentage_excluded',
        'outbound_rollout_mode_invalid',
        'outbound_rollout_percent_out_of_range',
        'canary_mode_without_canaries',
        'rollout_prerequisites_not_met',
        'temporarily_blocked',
        'outbound_suspended',
        'concurrent_queued_limit',
        'rate_limit_minute',
        'rate_limit_hour',
        'rate_limit_day',
        'unique_recipients_hour',
        'unique_recipients_day',
        'outbound_bytes_day',
        'domain_auth_rate_limited',
        'domain_auth_pending',
        'domain_auth_not_ready',
    ];

    public function __construct(
        private readonly OutboundDraftService $drafts,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly OutboundUsageService $usage,
        private readonly AuditLogWriter $auditLogWriter,
        private readonly OutboundAuthorizationService $authorization,
    ) {}

    /**
     * @return array{processed: int, dispatched: int, deferred: int, failed: int}
     */
    public function execute(int $batchSize): array
    {
        if (! (bool) config('outbound.schedule.enabled', true)) {
            return ['processed' => 0, 'dispatched' => 0, 'deferred' => 0, 'failed' => 0];
        }

        $now = now();
        $ids = OutboundMessage::query()
            ->where('state', OutboundMessageState::Scheduled->value)
            ->where('scheduled_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('schedule_next_attempt_at')
                    ->orWhere('schedule_next_attempt_at', '<=', $now);
            })
            ->whereNull('scheduled_claimed_at')
            ->orderBy('scheduled_at')
            ->limit(max(1, $batchSize))
            ->pluck('id');

        $stats = ['processed' => 0, 'dispatched' => 0, 'deferred' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $stats['processed']++;
            $outcome = $this->dispatchOne((string) $id);
            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * @return 'dispatched'|'deferred'|'failed'
     */
    private function dispatchOne(string $messageId): string
    {
        $dispatchJobId = null;
        $outcome = 'deferred';

        try {
            $outcome = DB::transaction(function () use ($messageId, &$dispatchJobId): string {
                $message = OutboundMessage::query()
                    ->with(['inbox.domain'])
                    ->whereKey($messageId)
                    ->lockForUpdate()
                    ->first();

                if ($message === null) {
                    return 'failed';
                }

                $user = User::query()->find($message->user_id);
                if ($user === null) {
                    return 'failed';
                }

                if (! $this->isDue($message)) {
                    return 'failed';
                }

                $claimed = OutboundMessage::query()
                    ->whereKey($message->getKey())
                    ->where('state', OutboundMessageState::Scheduled->value)
                    ->whereNull('scheduled_claimed_at')
                    ->where('scheduled_at', '<=', now())
                    ->update([
                        'scheduled_claimed_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($claimed !== 1) {
                    return 'failed';
                }

                $message->refresh();
                $scheduleVersion = (int) $message->schedule_version;
                $scheduledAtUtc = $message->scheduled_at?->toIso8601String();
                $scheduledTimezone = $message->scheduled_timezone;

                try {
                    $prepared = $this->drafts->prepareSendableContent($message, $user, null);
                    // A schedule is only an intent. Re-run the same central
                    // send/reply/forward gate immediately before queueing so
                    // subscription, plan, inbox/domain, sender-profile, and
                    // operation entitlements cannot be bypassed after the
                    // schedule was accepted.
                    $this->authorization->assertCanSend($user, $message->inbox, $message->operation);
                    $this->authorization->assertCanSchedule($user, $message->operation);
                    $this->rateLimiter->assertWithinLimits($user, [...$prepared['to'], ...$prepared['cc'], ...$prepared['bcc']], $prepared['attachment_bytes']);
                } catch (OutboundSendException $exception) {
                    if ($this->isTemporaryDefer($exception->errorCode)) {
                        $this->defer($message, $user, $scheduleVersion, $exception->errorCode);

                        return 'deferred';
                    }

                    $this->failToDraft($message, $user, $scheduleVersion, $exception->errorCode);

                    return 'failed';
                }

                $message->forceFill([
                    'state' => OutboundMessageState::Queued,
                    'to_recipients' => $prepared['to'],
                    'cc_recipients' => $prepared['cc'],
                    'bcc_recipients' => $prepared['bcc'],
                    'subject' => $prepared['subject'],
                    'text_body' => $prepared['text_body'],
                    'html_body' => $prepared['html_body'],
                    'from_display_name' => $prepared['from_display_name'],
                    'reply_to_address' => $prepared['reply_to_address'],
                    'reply_to_name' => $prepared['reply_to_name'],
                    'attachment_ids' => $prepared['attachment_ids'],
                    'in_reply_to' => $prepared['in_reply_to'],
                    'references' => $prepared['references'],
                    'request_fingerprint' => $prepared['request_fingerprint'],
                    'is_canary' => $prepared['is_canary'],
                    'queued_at' => now(),
                    'draft_submitted_at' => $message->draft_submitted_at ?? now(),
                    ...OutboundScheduleFieldHelper::cleared(),
                ])->save();

                $this->usage->reserve($user, $message, $message->idempotency_key, $prepared['attachment_bytes']);

                $this->auditLogWriter->write(
                    'outbound.schedule_dispatched',
                    (string) $user->getKey(),
                    $message,
                    ['state' => OutboundMessageState::Scheduled->value],
                    ['state' => OutboundMessageState::Queued->value],
                    [
                        'message_id' => (string) $message->getKey(),
                        'scheduled_at_utc' => $scheduledAtUtc,
                        'scheduled_timezone' => $scheduledTimezone,
                        'schedule_version' => $scheduleVersion,
                        'result_code' => 'dispatched',
                    ],
                );

                app(OutboundNotificationService::class)->notify($user, 'outbound.queued', $message, [], 'queued:'.$message->id);

                $dispatchJobId = (string) $message->getKey();

                return 'dispatched';
            });
        } catch (\Throwable) {
            $this->releaseClaimSafely($messageId);

            return 'deferred';
        }

        if ($dispatchJobId !== null) {
            DeliverOutboundMessageJob::dispatch($dispatchJobId)->afterCommit();
        }

        return $outcome;
    }

    private function isDue(OutboundMessage $message): bool
    {
        if ($message->state !== OutboundMessageState::Scheduled) {
            return false;
        }

        if ($message->scheduled_at === null || $message->scheduled_at->isFuture()) {
            return false;
        }

        if ($message->scheduled_claimed_at !== null) {
            return false;
        }

        if ($message->schedule_next_attempt_at !== null && $message->schedule_next_attempt_at->isFuture()) {
            return false;
        }

        return true;
    }

    private function defer(OutboundMessage $message, User $user, int $scheduleVersion, string $reasonCode): void
    {
        $deferSeconds = min(
            (int) config('outbound.schedule.defer_seconds', 300),
            (int) config('outbound.schedule.max_defer_seconds', 900),
        );

        $message->forceFill([
            'scheduled_claimed_at' => null,
            'schedule_defer_reason' => $reasonCode,
            'schedule_next_attempt_at' => now()->addSeconds(max(1, $deferSeconds)),
        ])->save();

        $this->auditLogWriter->write(
            'outbound.schedule_dispatch_deferred',
            (string) $user->getKey(),
            $message,
            null,
            null,
            [
                'message_id' => (string) $message->getKey(),
                'scheduled_at_utc' => $message->scheduled_at?->toIso8601String(),
                'scheduled_timezone' => $message->scheduled_timezone,
                'schedule_version' => $scheduleVersion,
                'result_code' => $reasonCode,
            ],
        );

        app(OutboundNotificationService::class)->notify(
            $user,
            'outbound.schedule_deferred',
            $message,
            [],
            'schedule_deferred:'.$message->id.':'.$reasonCode.':'.now()->format('Y-m-d'),
        );
    }

    private function failToDraft(OutboundMessage $message, User $user, int $scheduleVersion, string $reasonCode): void
    {
        $previousUtc = $message->scheduled_at?->toIso8601String();
        $previousTimezone = $message->scheduled_timezone;

        $message->forceFill([
            'state' => OutboundMessageState::Draft,
            ...OutboundScheduleFieldHelper::cleared(),
        ])->save();
        // A permanent pre-transport rejection (for example suppression or a
        // lost entitlement) returns the accepted schedule to a draft, so its
        // reservation must not remain outstanding.
        $this->usage->release((string) $message->getKey(), 'schedule_pre_transport_rejected');

        $this->auditLogWriter->write(
            'outbound.schedule_dispatch_failed',
            (string) $user->getKey(),
            $message,
            ['state' => OutboundMessageState::Scheduled->value],
            ['state' => OutboundMessageState::Draft->value],
            [
                'message_id' => (string) $message->getKey(),
                'scheduled_at_utc' => $previousUtc,
                'scheduled_timezone' => $previousTimezone,
                'schedule_version' => $scheduleVersion,
                'result_code' => $reasonCode,
            ],
        );

        app(OutboundNotificationService::class)->notify(
            $user,
            'outbound.schedule_failed',
            $message,
            [],
            'schedule_failed:'.$message->id.':'.$scheduleVersion,
        );
    }

    private function isTemporaryDefer(string $errorCode): bool
    {
        return in_array($errorCode, self::TEMPORARY_DEFER_CODES, true);
    }

    private function releaseClaimSafely(string $messageId): void
    {
        OutboundMessage::query()
            ->whereKey($messageId)
            ->where('state', OutboundMessageState::Scheduled->value)
            ->update([
                'scheduled_claimed_at' => null,
                'schedule_defer_reason' => 'infra_error',
                'schedule_next_attempt_at' => now()->addSeconds(
                    min(
                        (int) config('outbound.schedule.defer_seconds', 300),
                        (int) config('outbound.schedule.max_defer_seconds', 900),
                    ),
                ),
                'updated_at' => now(),
            ]);
    }
}
