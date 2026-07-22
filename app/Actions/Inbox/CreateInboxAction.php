<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\CreateInboxData;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxQuotaExceededException;
use App\Models\Inbox;
use App\Models\User;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Services\Entitlement\EntitlementService;
use App\Services\MailServer\MailServerSelectionService;
use Illuminate\Support\Facades\DB;

/**
 * Create and persist a new inbox from validated input data.
 *
 * Enforces the max_inboxes plan entitlement before persistence when an
 * authenticated user context is provided, and assigns an entitled mail
 * server inside a single transaction so the selection lock holds.
 */
final class CreateInboxAction
{
    /**
     * @param InboxRepositoryInterface   $inboxRepository            Inbox persistence contract.
     * @param EntitlementService         $entitlementService         Feature entitlement resolution service.
     * @param MailServerSelectionService $mailServerSelectionService Entitled mail server selection service.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
        private readonly EntitlementService $entitlementService,
        private readonly MailServerSelectionService $mailServerSelectionService,
        private readonly MailServerRepositoryInterface $mailServerRepository,
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
     * @throws EligibleMailServerUnavailableException When no eligible mail server is available.
     */
    public function execute(CreateInboxData $data, ?User $user = null): Inbox
    {
        return DB::transaction(function () use ($data, $user): Inbox {
            if ($user !== null) {
                $user = $this->lockUserForUpdate($user);
                $this->enforceQuota($user);

                $mailServer = $this->mailServerSelectionService->selectForUser($user);

                if ($mailServer === null) {
                    throw new EligibleMailServerUnavailableException;
                }

                $data = $data->withMailServerId($mailServer->id);
            } else {
                $poolKey = config('inbox.public_mail_server_pool');

                if (! is_string($poolKey) || trim($poolKey) === '') {
                    throw new EligibleMailServerUnavailableException;
                }

                $mailServer = $this->mailServerRepository
                    ->selectAvailableForPoolsForUpdate([trim($poolKey)]);

                if ($mailServer === null) {
                    throw new EligibleMailServerUnavailableException;
                }

                $data = $data->withMailServerId($mailServer->id);
            }

            return $this->inboxRepository->create($data);
        });
    }

    /**
     * Lock the user row for update within the current transaction.
     *
     * Authenticated provisioning must acquire the user lock before quota
     * checks and mail-server selection to keep lock ordering consistent.
     *
     * @param User $user The user to lock.
     *
     * @return User The locked user instance.
     */
    private function lockUserForUpdate(User $user): User
    {
        return User::query()
            ->whereKey($user->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Enforce the max_inboxes entitlement for the given user.
     *
     * Unlimited plans (no resolved value, missing limit key, or null limit)
     * skip counting entirely.
     *
     * @param User $user The locked user to enforce the quota for.
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
