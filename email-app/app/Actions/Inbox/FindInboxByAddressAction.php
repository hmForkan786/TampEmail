<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;

/**
 * Find an existing inbox by its full email address.
 */
final class FindInboxByAddressAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository Inbox persistence contract.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
    ) {}

    /**
     * Find the inbox for the given full email address.
     *
     * @param string $fullAddress Full email address.
     *
     * @return Inbox|null The matching inbox, if found.
     */
    public function execute(string $fullAddress): ?Inbox
    {
        return $this->inboxRepository->findByAddress($fullAddress);
    }
}
