<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\CreateInboxData;
use App\Exceptions\InboxQuotaExceededException;
use App\Models\Inbox;
use App\Models\User;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Services\Entitlement\EntitlementService;

/**
 * Create and persist a new inbox from validated input data.
 *
 * Enforces the max_inboxes plan entitlement before persistence when an
 * authenticated user context is provided.
 */
final class CreateInboxAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository    Inbox persistence contract.
     * @param EntitlementService       $entitlementService Feature entitlement resolution service.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
        private readonly EntitlementService $entitlementService,
    ) {}

    /**
     * Create and persist a new inbox.
     *
     * @param CreateInboxData $data Validated inbox creation data.
     * @param User|null       $user Authenticated user for quota enforcement, if any.
     *
     * @return Inbox The created inbox.
     *
     * @throws InboxQuotaExceededException When the user's inbox quota is exhausted.
     */
    public function execute(CreateInboxData $data, ?User $user = null): Inbox
    {
        if ($user !== null) {
            $this->enforceQuota($user);
        }

        return $this->inboxRepository->create($data);
    }

    /**
     * Enforce the max_inboxes entitlement for the given user.
     *
     * Unlimited plans (no resolved value, missing limit key, or null limit)
     * skip counting entirely.
     *
     * @param User $user The user to enforce the quota for.
     *
     * @throws InboxQuotaExceededException When the user's inbox quota is exhausted.
     */
    private function enforceQuota(User $user): void
    {
        $value = $this->entitlementService->featureValue($user, 'max_inboxes');

        if ($value === null || ! array_key_exists('limit', $value) || $value['limit'] === null) {
            return;
        }

        $count = $this->inboxRepository->countForUser($user->id);

        if ($count >= (int) $value['limit']) {
            throw new InboxQuotaExceededException;
        }
    }
}
