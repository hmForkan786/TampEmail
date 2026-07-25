<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\DTOs\Outbound\OutboundMessageData;
use App\Enums\OutboundDeliveryAttemptState;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundTransportResult;
use App\Enums\UserStatus;
use App\Exceptions\OutboundSendException;
use App\Models\Domain;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundDeliveryAttemptRecorder;
use App\Services\Outbound\OutboundDomainAuthenticationService;
use App\Services\Outbound\OutboundFailoverEligibility;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundProviderRegistry;
use App\Services\Outbound\OutboundRateLimiter;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Support\Facades\DB;

/**
 * Manual, platform-admin-only cross-provider retry.
 *
 * This is the *only* way an outbound message may ever be resent through a
 * provider other than the one it was originally attempted with. There is
 * no automatic cross-provider failover anywhere in this codebase — see
 * docs/OUTBOUND_PROVIDER_PORTABILITY.md — because duplicate-safety cannot
 * be proven for every failure shape. This action exists precisely because
 * an audited human decision is the safety boundary that substitutes for
 * that proof.
 *
 * Every check below is enforced again here even though several of them are
 * also enforced elsewhere (e.g. normal send-time authorization), because
 * this action is reachable from an admin surface that bypasses the normal
 * user-initiated send path — defense in depth, not redundancy.
 */
final class RetryOutboundMessageWithProviderAction
{
    public function __construct(
        private readonly OutboundProviderRegistry $providers,
        private readonly OutboundFailoverEligibility $eligibility,
        private readonly OutboundDomainAuthenticationService $domainAuth,
        private readonly OutboundSuppressionService $suppressions,
        private readonly OutboundLaunchControlService $launchControl,
        private readonly OutboundRateLimiter $rateLimiter,
        private readonly EntitlementService $entitlements,
        private readonly OutboundAttachmentSelector $attachmentSelector,
        private readonly OutboundDeliveryAttemptRecorder $attempts,
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * @throws OutboundSendException when the retry is denied. The message
     *                               is left completely untouched on
     *                               denial — this method either fully
     *                               performs one bounded delivery attempt
     *                               or makes no state change at all.
     */
    public function execute(OutboundMessage $message, string $targetProvider, User $admin): OutboundMessage
    {
        if (! $admin->isPlatformAdmin()) {
            $this->deny($message, $targetProvider, $admin, 'not_platform_admin', 403);
        }

        $targetProvider = strtolower(trim($targetProvider));

        $message = OutboundMessage::query()
            ->with(['user', 'inbox.domain', 'sourceEmail'])
            ->whereKey($message->getKey())
            ->lockForUpdate()
            ->first();

        if ($message === null) {
            throw new OutboundSendException('not_found', 'Outbound message not found.', 404);
        }

        if ($message->state !== OutboundMessageState::Failed) {
            $this->deny($message, $targetProvider, $admin, 'message_not_failed', 422);
        }

        // Allowlist only: never an arbitrary provider name, and never the
        // same identity the message already failed with unless that
        // identity is still primary or secondary (re-trying the same
        // provider through this action is allowed — it is no less safe —
        // but an unsupported/unconfigured name is always rejected).
        $primary = $this->providers->primaryProvider();
        $secondary = $this->providers->secondaryProvider();
        $allowlist = array_values(array_filter([$primary, $secondary]));
        if (! in_array($targetProvider, $allowlist, true)) {
            $this->deny($message, $targetProvider, $admin, 'provider_not_allowlisted', 422);
        }

        $latestAttempt = $this->attempts->latestAttempt($message);
        $eligibility = $this->eligibility->evaluate($message, $latestAttempt);
        if (! $eligibility['eligible']) {
            $this->deny($message, $targetProvider, $admin, $eligibility['reason_code'], 422);
        }

        $readiness = $this->providers->readiness($targetProvider);
        if (! ($readiness['ready'] ?? false)) {
            $this->deny($message, $targetProvider, $admin, 'provider_not_ready', 503);
        }

        $inbox = $message->inbox;
        $domain = $inbox?->domain;
        if (! $domain instanceof Domain) {
            $this->deny($message, $targetProvider, $admin, 'domain_not_found', 422);
        }

        if (! $domain->is_active || $domain->trashed() || ! $domain->outbound_enabled) {
            $this->deny($message, $targetProvider, $admin, 'domain_not_available', 422);
        }

        // Provider-specific: primary DKIM/SPF being verified never implies
        // the secondary is ready. Each provider identity has an
        // independent domain-authentication record.
        if (! $this->domainAuth->isDomainReady($domain, $targetProvider)) {
            $this->deny($message, $targetProvider, $admin, 'domain_auth_not_ready_for_provider', 422);
        }

        $user = $message->user;
        if (! $user instanceof User || $user->trashed() || $user->status !== UserStatus::Active) {
            $this->deny($message, $targetProvider, $admin, 'user_inactive', 422);
        }

        if (! config('outbound.enabled', false)) {
            $this->deny($message, $targetProvider, $admin, 'outbound_disabled', 403);
        }

        if (! $this->entitlements->hasFeature($user, $message->operation->featureKey())) {
            $this->deny($message, $targetProvider, $admin, 'entitlement_denied', 403);
        }

        try {
            $this->rateLimiter->assertNotBlocked($user);
        } catch (OutboundSendException $exception) {
            $this->deny($message, $targetProvider, $admin, $exception->errorCode, 429);
        }

        try {
            $this->suppressions->assertRecipientsAllowed([
                ...($message->to_recipients ?? []),
                ...($message->cc_recipients ?? []),
                ...($message->bcc_recipients ?? []),
            ], $user);
        } catch (OutboundSendException $exception) {
            $this->deny($message, $targetProvider, $admin, $exception->errorCode, 422);
        }

        // Rollout + emergency-stop gate, evaluated last among the policy
        // checks so a globally stopped rollout blocks even an otherwise
        // fully-eligible manual failover.
        try {
            $this->launchControl->assertRolloutEligible($user, $inbox);
        } catch (OutboundSendException $exception) {
            $this->deny($message, $targetProvider, $admin, $exception->errorCode, 503);
        }

        $transportAttachments = [];
        if ($message->operation === OutboundOperation::Forward && ($message->attachment_ids ?? []) !== []) {
            $source = $message->sourceEmail;
            if ($source === null) {
                $this->deny($message, $targetProvider, $admin, 'email_not_found', 404);
            }

            try {
                $selected = $this->attachmentSelector->selectForForward($source, $message->attachment_ids ?? []);
                $transportAttachments = $this->attachmentSelector->toTransportPayload($selected);
            } catch (\Throwable $exception) {
                $code = property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'attachment_unsafe';
                $this->deny($message, $targetProvider, $admin, $code, 422);
            }
        }

        return DB::transaction(function () use ($message, $targetProvider, $admin, $eligibility, $transportAttachments): OutboundMessage {
            $claimed = OutboundMessage::query()->whereKey($message->getKey())->lockForUpdate()->first();
            if ($claimed === null || $claimed->state !== OutboundMessageState::Failed) {
                throw new OutboundSendException('message_not_failed', 'Only failed messages can be retried with a specific provider.', 422);
            }

            $claimUpdated = OutboundMessage::query()
                ->whereKey($claimed->getKey())
                ->where('state', OutboundMessageState::Failed->value)
                ->update([
                    'state' => OutboundMessageState::Sending->value,
                    'sending_at' => now(),
                    'attempt_count' => $claimed->attempt_count + 1,
                    'transport_attempted_at' => null,
                    'updated_at' => now(),
                ]);

            if ($claimUpdated !== 1) {
                throw new OutboundSendException('message_not_failed', 'Only failed messages can be retried with a specific provider.', 422);
            }

            $claimed = $claimed->fresh();

            $this->attempts->start(
                $claimed,
                (string) config('outbound.transport'),
                $targetProvider,
                $eligibility['reason_code'],
            );

            $this->audit->write(
                'outbound.manual_provider_retry_requested',
                (string) $admin->getKey(),
                $claimed,
                ['state' => OutboundMessageState::Failed->value],
                ['state' => OutboundMessageState::Sending->value],
                [
                    'target_provider' => $targetProvider,
                    'reason_code' => $eligibility['reason_code'],
                    'attempt' => $claimed->attempt_count,
                    'operation' => $claimed->operation->value,
                ],
            );

            $payload = new OutboundMessageData(
                messageId: (string) $claimed->getKey(),
                fromAddress: $claimed->from_address,
                fromDisplayName: $claimed->from_display_name,
                to: $claimed->to_recipients ?? [],
                cc: $claimed->cc_recipients ?? [],
                bcc: $claimed->bcc_recipients ?? [],
                subject: (string) ($claimed->subject ?? ''),
                textBody: $claimed->text_body,
                htmlBody: $claimed->html_body,
                inReplyTo: $claimed->in_reply_to,
                references: $claimed->references,
                attachments: $transportAttachments,
            );

            OutboundMessage::query()
                ->whereKey($claimed->getKey())
                ->where('state', OutboundMessageState::Sending->value)
                ->update(['transport_attempted_at' => now(), 'updated_at' => now()]);

            $transport = $this->providers->resolveTransport($targetProvider);
            $result = $transport->send($payload);

            if ($result->result === OutboundTransportResult::Accepted) {
                OutboundMessage::query()
                    ->whereKey($claimed->getKey())
                    ->where('state', OutboundMessageState::Sending->value)
                    ->update([
                        'state' => OutboundMessageState::Sent->value,
                        'sent_at' => now(),
                        'provider' => $result->provider ?? $targetProvider,
                        'provider_message_id' => $result->providerMessageId,
                        'failure_code' => null,
                        'failure_message' => null,
                        'updated_at' => now(),
                    ]);

                $this->attempts->complete(
                    $claimed,
                    OutboundDeliveryAttemptState::Accepted,
                    result: $result->result->value,
                    providerMessageId: $result->providerMessageId,
                );

                $fresh = $claimed->fresh();
                $this->audit->write(
                    'outbound.manual_provider_retry_succeeded',
                    (string) $admin->getKey(),
                    $fresh,
                    ['state' => OutboundMessageState::Sending->value],
                    ['state' => OutboundMessageState::Sent->value],
                    [
                        'target_provider' => $targetProvider,
                        'attempt' => $fresh->attempt_count,
                    ],
                );

                return $fresh;
            }

            $attemptState = $result->result === OutboundTransportResult::TemporaryFailure
                ? OutboundDeliveryAttemptState::TemporaryFailure
                : OutboundDeliveryAttemptState::PermanentFailure;

            OutboundMessage::query()
                ->whereKey($claimed->getKey())
                ->where('state', OutboundMessageState::Sending->value)
                ->update([
                    'state' => OutboundMessageState::Failed->value,
                    'failed_at' => now(),
                    'failure_code' => $result->failureCode,
                    'failure_message' => $result->failureMessage,
                    'provider' => $result->provider ?? $targetProvider,
                    'updated_at' => now(),
                ]);

            $this->attempts->complete(
                $claimed,
                $attemptState,
                result: $result->result->value,
                failureCode: $result->failureCode,
            );

            $fresh = $claimed->fresh();
            $this->audit->write(
                'outbound.manual_provider_retry_failed',
                (string) $admin->getKey(),
                $fresh,
                ['state' => OutboundMessageState::Sending->value],
                ['state' => OutboundMessageState::Failed->value],
                [
                    'target_provider' => $targetProvider,
                    'failure_code' => $result->failureCode,
                    'attempt' => $fresh->attempt_count,
                ],
            );

            return $fresh;
        });
    }

    private function deny(OutboundMessage $message, string $targetProvider, User $admin, string $reasonCode, int $status): never
    {
        $this->audit->write(
            'outbound.manual_provider_retry_blocked',
            (string) $admin->getKey(),
            $message,
            null,
            null,
            [
                'target_provider' => $targetProvider,
                'reason_code' => $reasonCode,
                'message_id' => (string) $message->getKey(),
            ],
        );

        throw new OutboundSendException($reasonCode, 'Manual provider retry was denied.', $status);
    }
}
