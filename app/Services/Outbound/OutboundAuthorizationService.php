<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundOperation;
use App\Enums\UserStatus;
use App\Exceptions\OutboundSendException;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;

final class OutboundAuthorizationService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly OutboundDomainAuthenticationService $domainAuth,
        private readonly OutboundLaunchControlService $launchControl,
    ) {}

    public function assertCanSend(User $user, Inbox $inbox, OutboundOperation $operation = OutboundOperation::Send, ?string $apiKeyId = null): void
    {
        // Checked first: overrides every other enablement below, including
        // canaries and a 100% rollout.
        if ($this->launchControl->isEmergencyStopped()) {
            throw new OutboundSendException('outbound_emergency_stop', 'Outbound email is temporarily stopped.', 503);
        }

        if ($user->trashed() || $user->status !== UserStatus::Active) {
            throw new OutboundSendException('user_inactive', 'The user account cannot send outbound email.', 403);
        }

        if ((string) $inbox->user_id !== (string) $user->getKey()) {
            throw new OutboundSendException('inbox_forbidden', 'The inbox does not belong to the authenticated user.', 404);
        }

        if (! $inbox->is_active || $inbox->trashed() || $inbox->isExpired()) {
            throw new OutboundSendException('inbox_inactive', 'The inbox is not available for outbound email.', 422);
        }

        $inbox->loadMissing('domain');
        $domain = $inbox->domain;

        if (! $domain instanceof Domain || ! $domain->is_active || $domain->trashed()) {
            throw new OutboundSendException('domain_inactive', 'The inbox domain is not available for outbound email.', 422);
        }

        if (! $domain->outbound_enabled) {
            throw new OutboundSendException('domain_outbound_disabled', 'Outbound email is disabled for this domain.', 403);
        }

        $this->domainAuth->assertDomainReady($domain);

        if (! config('outbound.enabled', false)) {
            throw new OutboundSendException('outbound_disabled', 'Outbound email is disabled.', 403);
        }

        $flag = match ($operation) {
            OutboundOperation::Send => 'outbound.send_enabled',
            OutboundOperation::Reply => 'outbound.reply_enabled',
            OutboundOperation::Forward => 'outbound.forward_enabled',
        };

        if (! config($flag, false)) {
            throw new OutboundSendException('operation_disabled', 'This outbound operation is disabled.', 403);
        }

        if (! $this->entitlements->hasFeature($user, $operation->featureKey())) {
            throw new OutboundSendException('entitlement_denied', 'The current plan does not allow this outbound operation.', 403);
        }

        // Rollout gating never bypasses any of the checks above (domain
        // verification, entitlement, etc.) — it is strictly additive.
        $this->launchControl->assertRolloutEligible($user, $inbox, $apiKeyId);
    }
}
