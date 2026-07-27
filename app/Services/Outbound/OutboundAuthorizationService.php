<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundOperation;
use App\Enums\UserStatus;
use App\Exceptions\OutboundSendException;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;

final class OutboundAuthorizationService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly OutboundDomainAuthenticationService $domainAuth,
        private readonly OutboundLaunchControlService $launchControl,
        private readonly AuditLogWriter $audit,
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

        $domain = Domain::query()->find($inbox->domain_id);

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

        if (! $this->entitlements->allows($user, OutboundOperation::Send->featureKey())) {
            $this->denyCommercial($user, OutboundOperation::Send->featureKey(), $operation);
        }

        if ($operation !== OutboundOperation::Send && ! $this->entitlements->allows($user, $operation->featureKey())) {
            $this->denyCommercial($user, $operation->featureKey(), $operation);
        }

        // Rollout gating never bypasses any of the checks above (domain
        // verification, entitlement, etc.) — it is strictly additive.
        $this->launchControl->assertRolloutEligible($user, $inbox, $apiKeyId);
    }

    public function assertCanSchedule(User $user, OutboundOperation $operation): void
    {
        if (! $this->entitlements->allows($user, 'outbound.schedule')) {
            $this->denyCommercial($user, 'outbound.schedule', $operation);
        }
    }

    public function assertCanManageSenderProfiles(User $user): void
    {
        if (! $this->entitlements->allows($user, 'outbound.sender_profiles')) {
            $this->audit->write('commercial.sender_profile_denied', (string) $user->getKey(), $user, null, null, ['feature' => 'outbound.sender_profiles']);
            throw new OutboundSendException('feature_not_available', 'Your current plan does not include custom sender profiles.', 403);
        }
    }

    private function denyCommercial(User $user, string $feature, OutboundOperation $operation): never
    {
        $this->audit->write('commercial.outbound_feature_denied', (string) $user->getKey(), $user, null, null, [
            'feature' => $feature,
            'operation' => $operation->value,
        ]);

        throw new OutboundSendException('feature_not_available', 'Your current plan does not include this outbound feature.', 403);
    }
}
