<?php

declare(strict_types=1);

namespace App\Actions\MailServer;

use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;

/**
 * Delete an existing mail server.
 */
final class DeleteMailServerAction
{
    /**
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Delete the given mail server.
     *
     * @param MailServer $mailServer The mail server to delete.
     *
     * @return bool Whether the mail server was deleted.
     */
    public function execute(MailServer $mailServer): bool
    {
        return $this->mailServerRepository->delete($mailServer);
    }
}
