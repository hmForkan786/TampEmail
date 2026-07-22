<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\Models\Email;
use App\Repositories\Contracts\EmailRepositoryInterface;

/**
 * Delete an existing email.
 */
final class DeleteEmailAction
{
    /**
     * @param EmailRepositoryInterface $emailRepository Email persistence contract.
     */
    public function __construct(
        private readonly EmailRepositoryInterface $emailRepository,
    ) {}

    /**
     * Delete the given email.
     *
     * @param Email $email The email to delete.
     *
     * @return bool Whether the email was deleted.
     */
    public function execute(Email $email): bool
    {
        return $this->emailRepository->delete($email);
    }
}
