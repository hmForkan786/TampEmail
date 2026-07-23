<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\InboxMutationContext;
use App\Exceptions\InboxRenewalException;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\Inbox\InboxLifetimePolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RenewInboxAction
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditLogWriter $audit,
        private readonly InboxLifetimePolicy $policy,
    ) {}

    public function execute(Inbox $inbox, CarbonInterface $requested, User $owner, InboxMutationContext $context): Inbox
    {
        if ($context->isAnonymous() || $context->isScheduler()) {
            throw new InvalidArgumentException('Inbox renewal requires an API mutation context.');
        }

        $context->assertApiMutation((string) $owner->getKey());

        return DB::transaction(function () use ($inbox, $requested, $owner, $context): Inbox {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->first();
            $locked = Inbox::query()->whereKey($inbox->getKey())->lockForUpdate()->first();
            if (! $lockedOwner instanceof User || ! $locked instanceof Inbox || $locked->user_id !== $lockedOwner->id || ! $locked->is_active || $locked->isExpired()) {
                throw new InboxRenewalException('not_found', 'Inbox not found.');
            }
            if ($context->actorUserId !== (string) $lockedOwner->id) {
                throw new InvalidArgumentException('Mutation actor must match the inbox owner.');
            }
            if (! Domain::query()->whereKey($locked->domain_id)->active()->exists()) {
                throw new InboxRenewalException('not_found', 'Inbox domain is unavailable.');
            }
            $old = $locked->expires_at;
            if ($old === null) {
                throw new InboxRenewalException('invalid_expiry', 'Permanent inboxes cannot be renewed.');
            }
            if ($requested->lte($old)) {
                throw new InboxRenewalException('invalid_expiry', 'Expiration must extend the current expiration.');
            }
            $configured = $this->policy->maxExtensionHours();
            $entitled = $this->entitlements->featureValue($lockedOwner, 'inbox_max_lifetime_hours');
            $allowed = isset($entitled['limit']) && is_numeric($entitled['limit']) ? (int) $entitled['limit'] : $this->policy->maxAbsoluteHours();
            $max = min($configured, $allowed);
            if ($requested->gt($old->copy()->addHours($max))) {
                throw new InboxRenewalException('invalid_expiry', 'Expiration exceeds the allowed extension.');
            }
            if ($requested->gt($locked->created_at->copy()->addHours($allowed))) {
                throw new InboxRenewalException('invalid_expiry', 'Expiration exceeds the maximum lifetime.');
            }
            $locked->forceFill(['expires_at' => $requested])->save();
            $at = now();
            $this->audit->write(
                'inbox.expiration_extended',
                (string) $lockedOwner->id,
                $locked,
                ['expires_at' => $old->toIso8601String()],
                ['expires_at' => $requested->toIso8601String()],
                [
                    'source' => $context->source,
                    'api_key_id' => $context->apiKeyId,
                    'changed_at' => $at->toIso8601String(),
                ],
                $at,
            );

            return $locked->refresh();
        });
    }
}
