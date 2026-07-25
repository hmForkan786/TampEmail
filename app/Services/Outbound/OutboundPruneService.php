<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;
use App\Models\OutboundProviderEvent;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Fail-closed, bounded, dry-run-first outbound retention prune.
 *
 * Categories run in an order that preserves referential/audit integrity:
 * 1. Content redaction for messages past their (per-user) content
 *    retention window.
 * 2. Delivery-attempt rows past attempt retention (message not held).
 * 3. Provider-event rows past event retention (message not held).
 * 4. Hard delete of message metadata — only for messages the user has
 *    already hidden, whose content is already redacted, that are not
 *    held, and that have no remaining delivery-attempt/provider-event
 *    children (i.e. categories 2 and 3 have already cleared for them).
 *
 * Never touches `outbound_recipient_suppressions` (suppressions have
 * their own independent expiry/active lifecycle — see
 * docs/OUTBOUND_RETENTION_POLICY.md) and never deletes an inbound
 * `Attachment` row or file; only the outbound `attachment_ids` reference
 * is cleared during redaction.
 */
final class OutboundPruneService
{
    /**
     * Bounds how many rows are scanned per category per run regardless of
     * dry-run vs confirm, so behavior (and reported "eligible" counts) is
     * deterministic between the two modes.
     */
    private const MAX_CANDIDATE_SCAN = 2000;

    public function __construct(
        private readonly OutboundRetentionPolicy $retentionPolicy,
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function prune(bool $dryRun, bool $confirm, int $batchSize): array
    {
        $report = $this->emptyReport();
        $started = microtime(true);

        if (! config('outbound_retention.cleanup_enabled', false)) {
            $report['blocked'] = true;
            $report['blocked_reason'] = 'disabled';
            $report['duration'] = round(microtime(true) - $started, 3);

            return $report;
        }

        $effectiveDryRun = $dryRun || ! $confirm;

        if ($batchSize < 1) {
            $report['duration'] = round(microtime(true) - $started, 3);

            return $report;
        }

        if (! $effectiveDryRun && $batchSize > 1000) {
            throw new InvalidArgumentException('Outbound retention batch size is bounded to 1000.');
        }

        $report = $this->redactContent($report, $batchSize, $effectiveDryRun);
        $report = $this->pruneDeliveryAttempts($report, $batchSize, $effectiveDryRun);
        $report = $this->pruneProviderEvents($report, $batchSize, $effectiveDryRun);
        $report = $this->hardDeleteExpiredMessages($report, $batchSize, $effectiveDryRun);

        $report['duration'] = round(microtime(true) - $started, 3);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(): array
    {
        return [
            'eligible_content_redaction' => 0,
            'content_redacted' => 0,
            'eligible_attempts' => 0,
            'attempts_deleted' => 0,
            'eligible_provider_events' => 0,
            'provider_events_deleted' => 0,
            'eligible_hard_delete' => 0,
            'messages_hard_deleted' => 0,
            'held' => 0,
            'skipped' => 0,
            'failed' => 0,
            'blocked' => false,
            'blocked_reason' => null,
            'duration' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function redactContent(array $report, int $batchSize, bool $dryRun): array
    {
        $candidates = OutboundMessage::query()
            ->whereNull('content_redacted_at')
            ->with('user')
            ->orderBy('created_at')
            ->limit(self::MAX_CANDIDATE_SCAN)
            ->get();

        $mutated = 0;

        foreach ($candidates as $message) {
            if (! $dryRun && $mutated >= $batchSize) {
                break;
            }

            if ($message->isRetentionHeld()) {
                $report['held']++;

                continue;
            }

            if ($message->user === null) {
                $report['skipped']++;

                continue;
            }

            $days = $this->retentionPolicy->contentRetentionDays($message->user);

            if ($days < 1) {
                // Disabled (fail-closed) for this user's plan: never redact.
                continue;
            }

            if ($message->created_at === null || $message->created_at->gt(now()->subDays($days))) {
                continue;
            }

            $report['eligible_content_redaction']++;

            if ($dryRun) {
                continue;
            }

            try {
                if ($this->redactOne($message)) {
                    $report['content_redacted']++;
                    $mutated++;
                }
            } catch (Throwable $e) {
                $report['failed']++;
                report($e);
            }
        }

        return $report;
    }

    private function redactOne(OutboundMessage $message): bool
    {
        return DB::transaction(function () use ($message): bool {
            $locked = OutboundMessage::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->content_redacted_at !== null || $locked->isRetentionHeld()) {
                return false;
            }

            $locked->forceFill([
                'subject' => '[redacted]',
                'text_body' => null,
                'html_body' => null,
                'from_display_name' => null,
                'to_recipients' => $this->hashRecipients($locked->to_recipients ?? []),
                'cc_recipients' => $locked->cc_recipients !== null ? $this->hashRecipients($locked->cc_recipients) : null,
                'bcc_recipients' => $locked->bcc_recipients !== null ? $this->hashRecipients($locked->bcc_recipients) : null,
                'attachment_ids' => null,
                'content_redacted_at' => now(),
            ])->save();

            $this->audit->write(
                'outbound.content_redacted',
                null,
                $locked->fresh(),
                null,
                null,
                ['message_id' => (string) $locked->getKey(), 'state' => $locked->state->value],
            );

            return true;
        });
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string>
     */
    private function hashRecipients(array $recipients): array
    {
        return array_values(array_map(
            static fn (string $recipient): string => 'sha256:'.hash('sha256', strtolower(trim($recipient))),
            $recipients,
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function pruneDeliveryAttempts(array $report, int $batchSize, bool $dryRun): array
    {
        $days = (int) config('outbound_retention.attempt_days', 0);

        if ($days < 1) {
            return $report;
        }

        $cutoff = now()->subDays($days);

        $report['held'] += OutboundDeliveryAttempt::query()
            ->where('created_at', '<', $cutoff)
            ->whereHas('outboundMessage', fn (Builder $q) => $q->retentionHeld())
            ->count();

        $eligible = OutboundDeliveryAttempt::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('outboundMessage', fn (Builder $q) => $q->retentionHeld());

        $report['eligible_attempts'] = (clone $eligible)->limit(self::MAX_CANDIDATE_SCAN)->count();

        if ($dryRun) {
            return $report;
        }

        $ids = (clone $eligible)->orderBy('created_at')->limit($batchSize)->pluck('id');

        try {
            $report['attempts_deleted'] = OutboundDeliveryAttempt::query()->whereIn('id', $ids)->delete();
        } catch (Throwable $e) {
            $report['failed']++;
            report($e);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function pruneProviderEvents(array $report, int $batchSize, bool $dryRun): array
    {
        $days = (int) config('outbound_retention.provider_event_days', 0);

        if ($days < 1) {
            return $report;
        }

        $cutoff = now()->subDays($days);

        // Complaint/bounce -> suppression relationships are preserved
        // regardless: suppressions live independently in
        // outbound_recipient_suppressions with their own expiry, never
        // cascaded from provider-event deletion.
        $report['held'] += OutboundProviderEvent::query()
            ->where('created_at', '<', $cutoff)
            ->whereHas('outboundMessage', fn (Builder $q) => $q->retentionHeld())
            ->count();

        $eligible = OutboundProviderEvent::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('outboundMessage', fn (Builder $q) => $q->retentionHeld());

        $report['eligible_provider_events'] = (clone $eligible)->limit(self::MAX_CANDIDATE_SCAN)->count();

        if ($dryRun) {
            return $report;
        }

        $ids = (clone $eligible)->orderBy('created_at')->limit($batchSize)->pluck('id');

        try {
            $report['provider_events_deleted'] = OutboundProviderEvent::query()->whereIn('id', $ids)->delete();
        } catch (Throwable $e) {
            $report['failed']++;
            report($e);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function hardDeleteExpiredMessages(array $report, int $batchSize, bool $dryRun): array
    {
        $baseQuery = fn (): Builder => OutboundMessage::query()
            ->whereNotNull('user_deleted_at')
            ->whereNotNull('content_redacted_at')
            ->whereIn('state', [
                OutboundMessageState::Delivered->value,
                OutboundMessageState::Cancelled->value,
                OutboundMessageState::Failed->value,
            ])
            ->whereDoesntHave('deliveryAttempts')
            ->whereDoesntHave('providerEvents');

        $report['held'] += (clone $baseQuery())->retentionHeld()->count();

        $eligible = $baseQuery()->whereNot(fn (Builder $q) => $q->retentionHeld());

        $report['eligible_hard_delete'] = (clone $eligible)->limit(self::MAX_CANDIDATE_SCAN)->count();

        if ($dryRun) {
            return $report;
        }

        $ids = (clone $eligible)->orderBy('user_deleted_at')->limit($batchSize)->pluck('id');

        foreach ($ids as $id) {
            try {
                if ($this->hardDeleteOne((string) $id)) {
                    $report['messages_hard_deleted']++;
                }
            } catch (Throwable $e) {
                $report['failed']++;
                report($e);
            }
        }

        return $report;
    }

    private function hardDeleteOne(string $messageId): bool
    {
        return DB::transaction(function () use ($messageId): bool {
            $locked = OutboundMessage::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($locked === null
                || $locked->user_deleted_at === null
                || $locked->content_redacted_at === null
                || $locked->isRetentionHeld()
                || $locked->deliveryAttempts()->exists()
                || $locked->providerEvents()->exists()
            ) {
                return false;
            }

            $messageIdString = (string) $locked->getKey();
            $state = $locked->state->value;

            $locked->delete();

            $this->audit->write(
                'outbound.message_hard_deleted',
                null,
                null,
                null,
                null,
                ['message_id' => $messageIdString, 'state' => $state],
            );

            return true;
        });
    }
}
