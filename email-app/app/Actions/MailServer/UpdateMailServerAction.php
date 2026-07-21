<?php

declare(strict_types=1);

namespace App\Actions\MailServer;

use App\DTOs\MailServer\UpdateMailServerData;
use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;

/**
 * Update an existing mail server from partial input data.
 */
final class UpdateMailServerAction
{
    /**
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Update and persist changes to the given mail server.
     *
     * @param MailServer           $mailServer The mail server to update.
     * @param UpdateMailServerData $data       Validated mail server update data.
     *
     * @return MailServer The updated mail server.
     */
    public function execute(MailServer $mailServer, UpdateMailServerData $data): MailServer
    {
        return $this->mailServerRepository->update($mailServer, $data);
    }
}
