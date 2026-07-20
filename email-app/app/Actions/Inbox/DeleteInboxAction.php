<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;

/**
 * Delete an existing inbox.
 */
final class DeleteInboxAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository Inbox persistence contract.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
    ) {}

    /**
     * Delete the given inbox.
     */
    public function execute(Inbox $inbox): bool
    {
        return $this->inboxRepository->delete($inbox);
    }
}
