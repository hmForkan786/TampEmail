<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\DTOs\User\ChangePlatformRoleData;
use App\DTOs\User\ChangePlatformRoleResult;
use App\Enums\ApiKeyScope;
use App\Enums\PlatformRole;
use App\Exceptions\PlatformRoleChangeNotAllowedException;
use App\Exceptions\PlatformRoleTargetUnavailableException;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Canonical application path for changing a user's platform_role.
 *
 * Lock order inside the transaction:
 * 1. lock actor and target rows by ascending primary key
 * 2. re-check the actor is an active platform admin
 * 3. reject self-changes and missing/deleted targets
 * 4. revoke demotion-affected API keys
 * 5. assign platform_role via explicit attribute + save()
 * 6. append an immutable AuditLog for successful mutations
 */
final class ChangePlatformRoleAction
{
    public const AUDIT_ACTION = 'user.platform_role_changed';

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    /**
     * @throws PlatformRoleChangeNotAllowedException
     * @throws PlatformRoleTargetUnavailableException
     */
    public function execute(ChangePlatformRoleData $data): ChangePlatformRoleResult
    {
        return DB::transaction(function () use ($data): ChangePlatformRoleResult {
            [$actor, $target] = $this->lockActorAndTarget($data);

            if (! $actor->isPlatformAdmin()) {
                throw new PlatformRoleChangeNotAllowedException(
                    'Only an active platform admin may change platform roles.'
                );
            }

            if ((string) $actor->getKey() === (string) $target->getKey()) {
                throw new PlatformRoleChangeNotAllowedException(
                    'A user may not change their own platform role.'
                );
            }

            $oldRole = $target->platform_role instanceof PlatformRole
                ? $target->platform_role
                : PlatformRole::tryFrom((string) ($target->getAttributes()['platform_role'] ?? ''));

            if (! $oldRole instanceof PlatformRole) {
                throw new PlatformRoleTargetUnavailableException(
                    'The platform role change target has an invalid role state.'
                );
            }

            $changedAt = now();
            $newRole = $data->newRole;

            if ($oldRole === $newRole) {
                return new ChangePlatformRoleResult(
                    target: $target->fresh() ?? $target,
                    oldRole: $oldRole,
                    newRole: $newRole,
                    revokedKeyCount: 0,
                    changed: false,
                    changedAt: $changedAt,
                );
            }

            $revokedKeyCount = $this->revokeForTransition(
                (string) $target->getKey(),
                $oldRole,
                $newRole,
                $changedAt,
            );

            $target->platform_role = $newRole;
            $target->save();

            $this->auditLogWriter->write(
                action: self::AUDIT_ACTION,
                actorUserId: (string) $actor->getKey(),
                auditable: $target,
                oldValues: [
                    'platform_role' => $oldRole->value,
                ],
                newValues: [
                    'platform_role' => $newRole->value,
                ],
                metadata: [
                    'target_user_id' => (string) $target->getKey(),
                    'revoked_key_count' => $revokedKeyCount,
                    'changed_at' => $changedAt->toIso8601String(),
                ],
                occurredAt: $changedAt,
            );

            return new ChangePlatformRoleResult(
                target: $target->fresh() ?? $target,
                oldRole: $oldRole,
                newRole: $newRole,
                revokedKeyCount: $revokedKeyCount,
                changed: true,
                changedAt: $changedAt,
            );
        });
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockActorAndTarget(ChangePlatformRoleData $data): array
    {
        if ($data->actorUserId === '' || $data->targetUserId === '') {
            throw new PlatformRoleChangeNotAllowedException(
                'A valid actor and target are required for a platform role change.'
            );
        }

        $ids = [$data->actorUserId, $data->targetUserId];
        sort($ids, SORT_STRING);

        /** @var array<string, User> $locked */
        $locked = [];

        foreach (array_unique($ids) as $id) {
            $user = User::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($user instanceof User) {
                $locked[$id] = $user;
            }
        }

        $actor = $locked[$data->actorUserId] ?? null;
        $target = $locked[$data->targetUserId] ?? null;

        if (! $actor instanceof User) {
            throw new PlatformRoleChangeNotAllowedException(
                'The platform role change actor is unavailable.'
            );
        }

        if (! $target instanceof User) {
            throw new PlatformRoleTargetUnavailableException;
        }

        return [$actor, $target];
    }

    private function revokeForTransition(
        string $targetUserId,
        PlatformRole $oldRole,
        PlatformRole $newRole,
        \DateTimeInterface $revokedAt,
    ): int {
        $scopes = match (true) {
            $oldRole === PlatformRole::Admin && $newRole === PlatformRole::Operator => [
                ApiKeyScope::MailServersAdmin->value,
            ],
            ($oldRole === PlatformRole::Admin || $oldRole === PlatformRole::Operator)
                && $newRole === PlatformRole::User => [
                    ApiKeyScope::MailServersRead->value,
                    ApiKeyScope::MailServersWrite->value,
                    ApiKeyScope::MailServersAdmin->value,
                ],
            default => [],
        };

        if ($scopes === []) {
            return 0;
        }

        return $this->apiKeyRepository->revokeUnrevokedForUserWithAnyScope(
            $targetUserId,
            $scopes,
            $revokedAt,
        );
    }
}
