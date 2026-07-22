<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\DTOs\User\ChangeUserStatusData;
use App\DTOs\User\ChangeUserStatusResult;
use App\Enums\UserStatus;
use App\Exceptions\UserStatusChangeNotAllowedException;
use App\Exceptions\UserStatusTargetUnavailableException;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Canonical application path for changing a user's account status.
 *
 * Lock order inside the transaction:
 * 1. lock actor and target rows by ascending primary key
 * 2. re-check the actor is an active platform admin
 * 3. reject self-changes and missing/deleted targets
 * 4. revoke all non-revoked API keys when transitioning to a non-active status
 * 5. assign status via explicit attribute + save()
 * 6. append an immutable AuditLog for successful mutations
 */
final class ChangeUserStatusAction
{
    public const AUDIT_ACTION = 'user.status_changed';

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    /**
     * @throws UserStatusChangeNotAllowedException
     * @throws UserStatusTargetUnavailableException
     */
    public function execute(ChangeUserStatusData $data): ChangeUserStatusResult
    {
        return DB::transaction(function () use ($data): ChangeUserStatusResult {
            [$actor, $target] = $this->lockActorAndTarget($data);

            if (! $actor->isPlatformAdmin()) {
                throw new UserStatusChangeNotAllowedException(
                    'Only an active platform admin may change user status.'
                );
            }

            if ((string) $actor->getKey() === (string) $target->getKey()) {
                throw new UserStatusChangeNotAllowedException(
                    'A user may not change their own status.'
                );
            }

            $oldStatus = $target->status instanceof UserStatus
                ? $target->status
                : UserStatus::tryFrom((string) ($target->getAttributes()['status'] ?? ''));

            if (! $oldStatus instanceof UserStatus) {
                throw new UserStatusTargetUnavailableException(
                    'The user status change target has an invalid status state.'
                );
            }

            $changedAt = now();
            $newStatus = $data->newStatus;

            if ($oldStatus === $newStatus) {
                return new ChangeUserStatusResult(
                    target: $target->fresh() ?? $target,
                    oldStatus: $oldStatus,
                    newStatus: $newStatus,
                    revokedKeyCount: 0,
                    changed: false,
                    changedAt: $changedAt,
                );
            }

            $revokedKeyCount = $this->revokeForTransition(
                (string) $target->getKey(),
                $newStatus,
                $changedAt,
            );

            $target->status = $newStatus;
            $target->save();

            $this->auditLogWriter->write(
                action: self::AUDIT_ACTION,
                actorUserId: (string) $actor->getKey(),
                auditable: $target,
                oldValues: [
                    'status' => $oldStatus->value,
                ],
                newValues: [
                    'status' => $newStatus->value,
                ],
                metadata: [
                    'target_user_id' => (string) $target->getKey(),
                    'revoked_key_count' => $revokedKeyCount,
                    'changed_at' => $changedAt->toIso8601String(),
                ],
                occurredAt: $changedAt,
            );

            return new ChangeUserStatusResult(
                target: $target->fresh() ?? $target,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                revokedKeyCount: $revokedKeyCount,
                changed: true,
                changedAt: $changedAt,
            );
        });
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockActorAndTarget(ChangeUserStatusData $data): array
    {
        if ($data->actorUserId === '' || $data->targetUserId === '') {
            throw new UserStatusChangeNotAllowedException(
                'A valid actor and target are required for a user status change.'
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
            throw new UserStatusChangeNotAllowedException(
                'The user status change actor is unavailable.'
            );
        }

        if (! $target instanceof User) {
            throw new UserStatusTargetUnavailableException;
        }

        return [$actor, $target];
    }

    /**
     * Revoke all non-revoked keys when entering any non-active status.
     * Reactivation never restores previously revoked keys.
     */
    private function revokeForTransition(
        string $targetUserId,
        UserStatus $newStatus,
        DateTimeInterface $revokedAt,
    ): int {
        if ($newStatus === UserStatus::Active) {
            return 0;
        }

        return $this->apiKeyRepository->revokeAllUnrevokedForUser(
            $targetUserId,
            $revokedAt,
        );
    }
}
