<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;

/**
 * Find an existing inbox by its identifier.
 */
final class FindInboxByIdAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository Inbox persistence contract.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
    ) {}

    /**
     * Find the inbox for the given identifier.
     *
     * @param string $id Inbox identifier.
     *
     * @return Inbox|null The matching inbox, if found.
     */
    public function execute(string $id): ?Inbox
    {
        return $this->inboxRepository->findById($id);
    }
}
