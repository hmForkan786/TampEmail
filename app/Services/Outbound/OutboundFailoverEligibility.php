<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Actions\Outbound\RetryOutboundMessageWithProviderAction;
use App\Enums\OutboundMessageState;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;

/**
 * Determines whether a FAILED outbound message may safely be retried
 * through a different provider without risking a duplicate send.
 *
 * ## Core safety rule
 *
 * A message whose primary-provider acceptance is ambiguous must NEVER be
 * resent automatically (or silently) through a secondary provider. This
 * class exists to make that rule an explicit, testable policy rather than
 * an assumption scattered across call sites.
 *
 * ## Eligible ("safe pre-acceptance") failures
 *
 * Eligibility is intentionally narrow. It is granted ONLY when the failure
 * is provably local or network-resolution-only — i.e. it happened before
 * any byte could possibly have reached the remote provider:
 *
 * - Local transport/mailer configuration was invalid, unselected, or the
 *   configured driver was declared unavailable before any connection was
 *   attempted (`invalid_config`, `invalid_mailer`, `invalid_transport`,
 *   `transport_unavailable`).
 * - DNS resolution for the SMTP host failed (`dns_failure`) — by
 *   definition no TCP connection, let alone SMTP DATA, was ever attempted.
 *
 * ## NOT eligible (always blocked)
 *
 * - Any attempt flagged `ambiguous` (the delivery worker invoked the
 *   transport and then died before the outcome could be persisted — see
 *   {@see OutboundStaleSendingReconciliationService}). The transport may
 *   have already accepted the message.
 * - A message still flagged by stale-sending reconciliation
 *   (`reconciliation_flagged_at` set).
 * - Timeouts, connection resets, rate limiting, or any other transport
 *   failure that occurs *after* a connection may have been established —
 *   current instrumentation cannot prove these happened before the
 *   provider could have queued the message, so they are treated as
 *   ambiguous and blocked, even though some of them are today retried via
 *   the *same* provider by {@see DeliverOutboundMessageJob}.
 * - Permanent transport rejections, invalid-recipient, and message
 *   content/size rejections — retrying via a different provider would not
 *   change the outcome.
 * - Authentication failures (`credentials_rejected`, `tls_configuration`)
 *   — these prove a connection *was* established with the provider, so
 *   they are excluded even though the message was never queued for
 *   delivery; they are also a provider-specific credential problem, not a
 *   connectivity problem a secondary provider would fix.
 * - Domain-authentication failures, suppression, abuse blocks, and manual
 *   cancellation — these are policy decisions independent of which
 *   provider is used; failing over would not help and could mask a real
 *   problem.
 * - Attempts exhausted after retry (`stale_sending_attempts_exhausted`).
 *
 * This service never mutates anything and never sends mail; it is a pure
 * policy check reused by {@see RetryOutboundMessageWithProviderAction}
 * and by ops/tests.
 */
final class OutboundFailoverEligibility
{
    /**
     * @var list<string>
     */
    private const ELIGIBLE_FAILURE_CODES = [
        'invalid_config',
        'invalid_mailer',
        'invalid_transport',
        'transport_unavailable',
        'dns_failure',
    ];

    /**
     * @return array{eligible: bool, reason_code: string}
     */
    public function evaluate(OutboundMessage $message, ?OutboundDeliveryAttempt $attempt): array
    {
        if ($message->state !== OutboundMessageState::Failed) {
            return $this->result(false, 'message_not_failed');
        }

        if ($message->reconciliation_flagged_at !== null) {
            return $this->result(false, 'ambiguous_flagged');
        }

        if ($attempt === null) {
            return $this->result(false, 'no_attempt_evidence');
        }

        if ($attempt->ambiguous) {
            return $this->result(false, 'ambiguous_attempt');
        }

        if (! $attempt->state->isTerminal()) {
            return $this->result(false, 'attempt_not_terminal');
        }

        $code = strtolower(trim((string) ($message->failure_code ?? '')));
        if ($code === '' || ! in_array($code, self::ELIGIBLE_FAILURE_CODES, true)) {
            return $this->result(false, 'not_pre_acceptance_safe');
        }

        return $this->result(true, 'safe_pre_acceptance_failure');
    }

    public function isEligible(OutboundMessage $message, ?OutboundDeliveryAttempt $attempt): bool
    {
        return $this->evaluate($message, $attempt)['eligible'];
    }

    /**
     * @return list<string>
     */
    public function eligibleFailureCodes(): array
    {
        return self::ELIGIBLE_FAILURE_CODES;
    }

    /**
     * @return array{eligible: bool, reason_code: string}
     */
    private function result(bool $eligible, string $reasonCode): array
    {
        return ['eligible' => $eligible, 'reason_code' => $reasonCode];
    }
}
