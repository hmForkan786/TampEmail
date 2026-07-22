<?php

declare(strict_types=1);

namespace App\DTOs\User;

use App\Enums\PlatformRole;

/**
 * Input for the canonical platform role change action.
 *
 * Actor capability must be resolved from the locked actor row, never inferred
 * from this payload alone.
 */
final readonly class ChangePlatformRoleData
{
    public function __construct(
        public string $actorUserId,
        public string $targetUserId,
        public PlatformRole $newRole,
    ) {}
}
