<?php

declare(strict_types=1);

namespace App\DTOs\User;

use App\Enums\PlatformRole;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Outcome of a platform role change, suitable for downstream audit logging.
 */
final readonly class ChangePlatformRoleResult
{
    public function __construct(
        public User $target,
        public PlatformRole $oldRole,
        public PlatformRole $newRole,
        public int $revokedKeyCount,
        public bool $changed,
        public CarbonInterface $changedAt,
    ) {}
}
