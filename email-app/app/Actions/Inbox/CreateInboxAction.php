<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\CreateInboxData;
use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;

/**
 * Create and persist a new inbox from validated input data.
 */
final class CreateInboxAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository Inbox persistence contract.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
    ) {}

    /**
     * Create and persist a new inbox.
     */
    public function execute(CreateInboxData $data): Inbox
    {
        return $this->inboxRepository->create($data);
    }
}
