<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\Models\Email;
use App\Repositories\Contracts\EmailRepositoryInterface;

/**
 * Find an existing email by its identifier.
 */
final class FindEmailByIdAction
{
    /**
     * @param EmailRepositoryInterface $emailRepository Email persistence contract.
     */
    public function __construct(
        private readonly EmailRepositoryInterface $emailRepository,
    ) {}

    /**
     * Find the email for the given identifier.
     *
     * @param string $id Email identifier.
     *
     * @return Email|null The matching email, if found.
     */
    public function execute(string $id): ?Email
    {
        return $this->emailRepository->findById($id);
    }
}
