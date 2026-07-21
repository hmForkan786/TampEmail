<?php

declare(strict_types=1);

namespace App\Actions\MailServer;

use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;

/**
 * Find an existing mail server by its identifier.
 */
final class FindMailServerByIdAction
{
    /**
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Find the mail server for the given identifier.
     *
     * @param string $id Mail server identifier.
     *
     * @return MailServer|null The matching mail server, if found.
     */
    public function execute(string $id): ?MailServer
    {
        return $this->mailServerRepository->findById($id);
    }
}
