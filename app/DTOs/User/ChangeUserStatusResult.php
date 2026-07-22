<?php

declare(strict_types=1);

namespace App\DTOs\User;

use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Outcome of a user status change, including demotion-time key revocation stats.
 */
final readonly class ChangeUserStatusResult
{
    public function __construct(
        public User $target,
        public UserStatus $oldStatus,
        public UserStatus $newStatus,
        public int $revokedKeyCount,
        public bool $changed,
        public CarbonInterface $changedAt,
    ) {}
}
