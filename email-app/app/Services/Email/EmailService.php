<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Actions\Email\CreateEmailAction;
use App\Actions\Email\DeleteEmailAction;
use App\Actions\Email\FindEmailByIdAction;
use App\Actions\Email\FindEmailByMessageIdAction;
use App\Actions\Email\PaginateEmailsAction;
use App\Actions\Email\UpdateEmailAction;
use App\DTOs\Email\CreateEmailData;
use App\DTOs\Email\EmailFiltersData;
use App\DTOs\Email\UpdateEmailData;
use App\Models\Email;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate email operations for controllers, API and Filament.
 */
final class EmailService
{
    /**
     * @param CreateEmailAction            $createEmailAction            Create email action.
     * @param UpdateEmailAction            $updateEmailAction            Update email action.
     * @param DeleteEmailAction            $deleteEmailAction            Delete email action.
     * @param FindEmailByIdAction          $findEmailByIdAction          Find email by ID action.
     * @param FindEmailByMessageIdAction   $findEmailByMessageIdAction   Find email by message ID action.
     * @param PaginateEmailsAction         $paginateEmailsAction         Paginate emails action.
     */
    public function __construct(
        private readonly CreateEmailAction $createEmailAction,
        private readonly UpdateEmailAction $updateEmailAction,
        private readonly DeleteEmailAction $deleteEmailAction,
        private readonly FindEmailByIdAction $findEmailByIdAction,
        private readonly FindEmailByMessageIdAction $findEmailByMessageIdAction,
        private readonly PaginateEmailsAction $paginateEmailsAction,
    ) {}

    /**
     * Create and persist a new email.
     *
     * @param CreateEmailData $data Validated email creation data.
     *
     * @return Email The created email.
     */
    public function create(CreateEmailData $data): Email
    {
        return $this->createEmailAction->execute($data);
    }

    /**
     * Update and persist changes to the given email.
     *
     * @param Email           $email The email to update.
     * @param UpdateEmailData $data  Validated email update data.
     *
     * @return Email The updated email.
     */
    public function update(Email $email, UpdateEmailData $data): Email
    {
        return $this->updateEmailAction->execute($email, $data);
    }

    /**
     * Delete the given email.
     *
     * @param Email $email The email to delete.
     *
     * @return bool Whether the email was deleted.
     */
    public function delete(Email $email): bool
    {
        return $this->deleteEmailAction->execute($email);
    }

    /**
     * Find an email by its identifier.
     *
     * @param string $id Email identifier.
     *
     * @return Email|null The matching email, if found.
     */
    public function findById(string $id): ?Email
    {
        return $this->findEmailByIdAction->execute($id);
    }

    /**
     * Find an email by its unique message identifier.
     *
     * @param string $messageId Unique external message identifier.
     *
     * @return Email|null The matching email, if found.
     */
    public function findByMessageId(string $messageId): ?Email
    {
        return $this->findEmailByMessageIdAction->execute($messageId);
    }

    /**
     * Retrieve a paginated list of emails for the given filters.
     *
     * @param EmailFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated email results.
     */
    public function paginate(EmailFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateEmailsAction->execute($filters);
    }
}
