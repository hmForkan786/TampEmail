<?php

declare(strict_types=1);

namespace App\Actions\MailServer;

use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;

/**
 * Find an existing mail server by its unique hostname.
 */
final class FindMailServerByHostnameAction
{
    /**
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Find the mail server for the given hostname.
     *
     * @param string $hostname Unique mail server hostname.
     *
     * @return MailServer|null The matching mail server, if found.
     */
    public function execute(string $hostname): ?MailServer
    {
        return $this->mailServerRepository->findByHostname($hostname);
    }
}
