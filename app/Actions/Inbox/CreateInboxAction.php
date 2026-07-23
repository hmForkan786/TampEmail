<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxQuotaExceededException;
use App\Models\Inbox;
use App\Models\User;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\MailServer\MailServerSelectionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create and persist a new inbox from validated input data.
 *
 * Enforces the max_inboxes plan entitlement before persistence when an
 * authenticated user context is provided, and assigns an entitled mail
 * server inside a single transaction so the selection lock holds.
 */
final class CreateInboxAction
{
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
        private readonly EntitlementService $entitlementService,
        private readonly MailServerSelectionService $mailServerSelectionService,
        private readonly MailServerRepositoryInterface $mailServerRepository,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    /**
     * Create and persist a new inbox.
     *
     * @throws InboxQuotaExceededException
     * @throws EligibleMailServerUnavailableException
     * @throws InvalidArgumentException When the mutation context is invalid for this flow.
     */
    public function execute(CreateInboxData $data, ?User $user, InboxMutationContext $context): Inbox
    {
        $this->assertContextAllowsCreate($data, $user, $context);

        return DB::transaction(function () use ($data, $user, $context): Inbox {
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

            $inbox = $this->inboxRepository->create($data);
            $inbox->refresh();
            $at = now();
            $this->auditLogWriter->write('inbox.created', $context->actorUserId, $inbox, [], [
                'is_active' => $inbox->is_active,
                'expires_at' => $inbox->expires_at,
            ], [
                'source' => $context->source,
                'api_key_id' => $context->apiKeyId,
                'domain_id' => $inbox->domain_id,
                'anonymous' => $context->isAnonymous(),
                'changed_at' => $at->toIso8601String(),
            ], $at);

            return $inbox;
        });
    }

    private function assertContextAllowsCreate(CreateInboxData $data, ?User $user, InboxMutationContext $context): void
    {
        if ($context->isScheduler()) {
            throw new InvalidArgumentException('Scheduler context cannot create an inbox.');
        }

        if ($context->isApi()) {
            if ($user === null) {
                throw new InvalidArgumentException('API inbox creation requires an authenticated owner.');
            }
            $context->assertApiMutation((string) $user->getKey());
            if ($data->userId === null || $data->userId === '' || $data->userId !== (string) $user->getKey()) {
                throw new InvalidArgumentException('Create payload owner must match the mutation actor.');
            }

            return;
        }

        if ($context->isAnonymous()) {
            $context->assertAnonymousCreate();
            if ($user !== null || ($data->userId !== null && $data->userId !== '')) {
                throw new InvalidArgumentException('Anonymous context cannot create a user-owned inbox.');
            }
        }
    }

    private function lockUserForUpdate(User $user): User
    {
        return User::query()
            ->whereKey($user->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

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
