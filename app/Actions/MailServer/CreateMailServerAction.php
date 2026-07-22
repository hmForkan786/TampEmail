<?php

declare(strict_types=1);

namespace App\Actions\MailServer;

use App\DTOs\MailServer\CreateMailServerData;
use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Support\MailServerProvisioningInvariant;

/**
 * Create and persist a new mail server from validated input data.
 */
final class CreateMailServerAction
{
    /**
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Create and persist a new mail server.
     *
     * @param CreateMailServerData $data Validated mail server creation data.
     *
     * @return MailServer The created mail server.
     */
    public function execute(CreateMailServerData $data): MailServer
    {
        $data = $data->withProvisioningFields(
            MailServerProvisioningInvariant::poolKey($data->poolKey),
            MailServerProvisioningInvariant::maxInboxes($data->maxInboxes),
        );

        return $this->mailServerRepository->create($data);
    }
}
