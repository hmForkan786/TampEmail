<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Actions\Inbox\CreateInboxAction;
use App\Actions\Inbox\DeleteInboxAction;
use App\Actions\Inbox\FindInboxByAddressAction;
use App\Actions\Inbox\FindInboxByIdAction;
use App\Actions\Inbox\PaginateInboxesAction;
use App\Actions\Inbox\UpdateInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxFiltersData;
use App\DTOs\Inbox\UpdateInboxData;
use App\Models\Inbox;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate inbox operations for controllers, API and Filament.
 */
final class InboxService
{
    /**
     * @param CreateInboxAction          $createInboxAction          Create inbox action.
     * @param UpdateInboxAction          $updateInboxAction          Update inbox action.
     * @param DeleteInboxAction          $deleteInboxAction          Delete inbox action.
     * @param FindInboxByIdAction          $findInboxByIdAction          Find inbox by ID action.
     * @param FindInboxByAddressAction     $findInboxByAddressAction     Find inbox by address action.
     * @param PaginateInboxesAction        $paginateInboxesAction        Paginate inboxes action.
     */
    public function __construct(
        private readonly CreateInboxAction $createInboxAction,
        private readonly UpdateInboxAction $updateInboxAction,
        private readonly DeleteInboxAction $deleteInboxAction,
        private readonly FindInboxByIdAction $findInboxByIdAction,
        private readonly FindInboxByAddressAction $findInboxByAddressAction,
        private readonly PaginateInboxesAction $paginateInboxesAction,
    ) {}

    /**
     * Create and persist a new inbox.
     *
     * @param CreateInboxData $data Validated inbox creation data.
     *
     * @return Inbox The created inbox.
     */
    public function create(CreateInboxData $data): Inbox
    {
        return $this->createInboxAction->execute($data);
    }

    /**
     * Update and persist changes to the given inbox.
     *
     * @param Inbox            $inbox The inbox to update.
     * @param UpdateInboxData  $data  Validated inbox update data.
     *
     * @return Inbox The updated inbox.
     */
    public function update(Inbox $inbox, UpdateInboxData $data): Inbox
    {
        return $this->updateInboxAction->execute($inbox, $data);
    }

    /**
     * Delete the given inbox.
     *
     * @param Inbox $inbox The inbox to delete.
     *
     * @return bool Whether the inbox was deleted.
     */
    public function delete(Inbox $inbox): bool
    {
        return $this->deleteInboxAction->execute($inbox);
    }

    /**
     * Find an inbox by its identifier.
     *
     * @param string $id Inbox identifier.
     *
     * @return Inbox|null The matching inbox, if found.
     */
    public function findById(string $id): ?Inbox
    {
        return $this->findInboxByIdAction->execute($id);
    }

    /**
     * Find an inbox by its full email address.
     *
     * @param string $fullAddress Full email address.
     *
     * @return Inbox|null The matching inbox, if found.
     */
    public function findByAddress(string $fullAddress): ?Inbox
    {
        return $this->findInboxByAddressAction->execute($fullAddress);
    }

    /**
     * Retrieve a paginated list of inboxes for the given filters.
     *
     * @param InboxFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated inbox results.
     */
    public function paginate(InboxFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateInboxesAction->execute($filters);
    }
}
