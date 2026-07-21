<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\DTOs\Email\CreateEmailData;
use App\Models\Email;
use App\Repositories\Contracts\EmailRepositoryInterface;

/**
 * Create and persist a new email from validated input data.
 */
final class CreateEmailAction
{
    /**
     * @param EmailRepositoryInterface $emailRepository Email persistence contract.
     */
    public function __construct(
        private readonly EmailRepositoryInterface $emailRepository,
    ) {}

    /**
     * Create and persist a new email.
     *
     * @param CreateEmailData $data Validated email creation data.
     *
     * @return Email The created email.
     */
    public function execute(CreateEmailData $data): Email
    {
        return $this->emailRepository->create($data);
    }
}
