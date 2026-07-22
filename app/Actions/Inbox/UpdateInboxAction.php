<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\UpdateInboxData;
use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;

/**
 * Update an existing inbox from partial input data.
 */
final class UpdateInboxAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository Inbox persistence contract.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
    ) {}

    /**
     * Update and persist changes to the given inbox.
     */
    public function execute(Inbox $inbox, UpdateInboxData $data): Inbox
    {
        return $this->inboxRepository->update($inbox, $data);
    }
}
