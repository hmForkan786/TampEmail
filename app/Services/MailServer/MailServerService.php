<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Actions\MailServer\CreateMailServerAction;
use App\Actions\MailServer\DeleteMailServerAction;
use App\Actions\MailServer\FindMailServerByHostnameAction;
use App\Actions\MailServer\FindMailServerByIdAction;
use App\Actions\MailServer\PaginateMailServersAction;
use App\Actions\MailServer\UpdateMailServerAction;
use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\MailServer\MailServerMutationContext;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Models\MailServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate mail server operations for controllers, API and Filament.
 */
final class MailServerService
{
    /**
     * @param CreateMailServerAction         $createMailServerAction         Create mail server action.
     * @param UpdateMailServerAction         $updateMailServerAction         Update mail server action.
     * @param DeleteMailServerAction         $deleteMailServerAction         Delete mail server action.
     * @param FindMailServerByIdAction       $findMailServerByIdAction       Find mail server by ID action.
     * @param FindMailServerByHostnameAction $findMailServerByHostnameAction Find mail server by hostname action.
     * @param PaginateMailServersAction      $paginateMailServersAction      Paginate mail servers action.
     */
    public function __construct(
        private readonly CreateMailServerAction $createMailServerAction,
        private readonly UpdateMailServerAction $updateMailServerAction,
        private readonly DeleteMailServerAction $deleteMailServerAction,
        private readonly FindMailServerByIdAction $findMailServerByIdAction,
        private readonly FindMailServerByHostnameAction $findMailServerByHostnameAction,
        private readonly PaginateMailServersAction $paginateMailServersAction,
    ) {}

    /**
     * Create and persist a new mail server.
     *
     * @param CreateMailServerData $data Validated mail server creation data.
     *
     * @return MailServer The created mail server.
     */
    public function create(CreateMailServerData $data, MailServerMutationContext $context): MailServer
    {
        return $this->createMailServerAction->execute($data, $context);
    }

    /**
     * Update and persist changes to the given mail server.
     *
     * @param MailServer           $mailServer The mail server to update.
     * @param UpdateMailServerData $data       Validated mail server update data.
     *
     * @return MailServer The updated mail server.
     */
    public function update(MailServer $mailServer, UpdateMailServerData $data, MailServerMutationContext $context): MailServer
    {
        return $this->updateMailServerAction->execute($mailServer, $data, $context);
    }

    /**
     * Delete the given mail server.
     *
     * @param MailServer $mailServer The mail server to delete.
     *
     * @return bool Whether the mail server was deleted.
     */
    public function delete(MailServer $mailServer): bool
    {
        return $this->deleteMailServerAction->execute($mailServer);
    }

    /**
     * Find a mail server by its identifier.
     *
     * @param string $id Mail server identifier.
     *
     * @return MailServer|null The matching mail server, if found.
     */
    public function findById(string $id): ?MailServer
    {
        return $this->findMailServerByIdAction->execute($id);
    }

    /**
     * Find a mail server by its unique hostname.
     *
     * @param string $hostname Unique mail server hostname.
     *
     * @return MailServer|null The matching mail server, if found.
     */
    public function findByHostname(string $hostname): ?MailServer
    {
        return $this->findMailServerByHostnameAction->execute($hostname);
    }

    /**
     * Retrieve a paginated list of mail servers for the given filters.
     *
     * @param MailServerFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated mail server results.
     */
    public function paginate(MailServerFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateMailServersAction->execute($filters);
    }
}
