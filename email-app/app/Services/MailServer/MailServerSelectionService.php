<?php

declare(strict_types=1);

namespace App\Services\MailServer;

use App\Models\MailServer;
use App\Models\User;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Services\Entitlement\EntitlementService;

/**
 * Select an entitled mail server for a user prior to inbox creation.
 *
 * Resolves the mail_server_pools entitlement, normalizes the allowed pool
 * keys, and delegates deterministic locked selection to the repository.
 * Expected misses return null; it owns no transaction, so callers must wrap
 * selection and persistence in one transaction for the row lock to hold.
 */
final class MailServerSelectionService
{
    /**
     * @param EntitlementService            $entitlementService   Feature entitlement resolution service.
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Select and lock the best available mail server for the given user.
     *
     * @param User $user The user to select a mail server for.
     *
     * @return MailServer|null The locked selected mail server, if any.
     */
    public function selectForUser(User $user): ?MailServer
    {
        $value = $this->entitlementService->featureValue($user, 'mail_server_pools');

        if ($value === null || ! array_key_exists('pools', $value) || ! is_array($value['pools'])) {
            return null;
        }

        $poolKeys = $this->normalizePoolKeys($value['pools']);

        if ($poolKeys === []) {
            return null;
        }

        return $this->mailServerRepository
            ->selectAvailableForPoolsForUpdate($poolKeys);
    }

    /**
     * Normalize entitled pool keys to unique, trimmed, non-empty strings.
     *
     * @param array<mixed> $pools Raw pools payload from the entitlement.
     *
     * @return array<string> Normalized pool keys, reindexed.
     */
    private function normalizePoolKeys(array $pools): array
    {
        $normalized = [];

        foreach ($pools as $pool) {
            if (! is_string($pool)) {
                continue;
            }

            $pool = trim($pool);

            if ($pool === '') {
                continue;
            }

            $normalized[] = $pool;
        }

        return array_values(array_unique($normalized));
    }
}
