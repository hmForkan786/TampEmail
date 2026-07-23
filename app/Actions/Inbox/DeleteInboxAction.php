<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\InboxMutationContext;
use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Delete (deactivate + soft-delete) an existing inbox.
 */
final class DeleteInboxAction
{
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    /**
     * Delete the given inbox.
     *
     * @throws InvalidArgumentException When the mutation context is invalid for this flow.
     */
    public function execute(Inbox $inbox, InboxMutationContext $context): bool
    {
        if ($context->isAnonymous() || $context->isScheduler()) {
            throw new InvalidArgumentException('Inbox deletion requires an API mutation context.');
        }

        return DB::transaction(function () use ($inbox, $context): bool {
            $locked = Inbox::query()->whereKey($inbox->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->user_id === null || $locked->user_id === '') {
                throw new InvalidArgumentException('Anonymous inboxes cannot be deleted through the API mutation context.');
            }

            $context->assertApiMutation((string) $locked->user_id);

            $locked->forceFill(['is_active' => false])->save();
            $deleted = $this->inboxRepository->delete($locked);

            if ($deleted) {
                $at = now();
                $this->auditLogWriter->write('inbox.deactivated', $context->actorUserId, $locked, ['is_active' => true], ['is_active' => false], [
                    'source' => $context->source,
                    'api_key_id' => $context->apiKeyId,
                    'changed_at' => $at->toIso8601String(),
                ], $at);
            }

            return $deleted;
        });
    }
}
