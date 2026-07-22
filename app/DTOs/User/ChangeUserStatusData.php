<?php

declare(strict_types=1);

namespace App\DTOs\User;

use App\Enums\UserStatus;

/**
 * Input for the canonical user status change action.
 *
 * Actor capability must be resolved from the locked actor row, never inferred
 * from this payload alone.
 */
final readonly class ChangeUserStatusData
{
    public function __construct(
        public string $actorUserId,
        public string $targetUserId,
        public UserStatus $newStatus,
    ) {}
}
