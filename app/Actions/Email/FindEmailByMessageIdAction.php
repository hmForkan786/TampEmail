<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\Models\Email;
use App\Repositories\Contracts\EmailRepositoryInterface;

/**
 * Find an existing email by its unique message identifier.
 */
final class FindEmailByMessageIdAction
{
    /**
     * @param EmailRepositoryInterface $emailRepository Email persistence contract.
     */
    public function __construct(
        private readonly EmailRepositoryInterface $emailRepository,
    ) {}

    /**
     * Find the email for the given message identifier.
     *
     * @param string $messageId Unique external message identifier.
     *
     * @return Email|null The matching email, if found.
     */
    public function execute(string $messageId): ?Email
    {
        return $this->emailRepository->findByMessageId($messageId);
    }
}
