<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\DTOs\Email\UpdateEmailData;
use App\Models\Email;
use App\Repositories\Contracts\EmailRepositoryInterface;

/**
 * Update an existing email from partial input data.
 */
final class UpdateEmailAction
{
    /**
     * @param EmailRepositoryInterface $emailRepository Email persistence contract.
     */
    public function __construct(
        private readonly EmailRepositoryInterface $emailRepository,
    ) {}

    /**
     * Update and persist changes to the given email.
     *
     * @param Email           $email The email to update.
     * @param UpdateEmailData $data  Validated email update data.
     *
     * @return Email The updated email.
     */
    public function execute(Email $email, UpdateEmailData $data): Email
    {
        return $this->emailRepository->update($email, $data);
    }
}
