<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Laravel-compatible email verification with status-aware activation.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly IdentityAnalyticsRecorder $analytics,
    ) {}

    public function markVerified(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        if ($user->trashed() || $user->status->isBlocked()) {
            $this->audit->write('identity.verification_failed', (string) $user->getKey(), $user, metadata: [
                'reason' => 'blocked_status',
                'status' => $user->status->value,
            ]);

            throw new AuthorizationException('This account cannot be verified.');
        }

        return DB::transaction(function () use ($user): bool {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                return false;
            }

            if ($locked->hasVerifiedEmail()) {
                return true;
            }

            if ($locked->status->isBlocked()) {
                $this->audit->write('identity.verification_failed', (string) $locked->getKey(), $locked, metadata: [
                    'reason' => 'blocked_status',
                ]);
                throw new AuthorizationException('This account cannot be verified.');
            }

            $locked->forceFill([
                'email_verified_at' => now(),
                'status' => UserStatus::Active,
            ])->save();

            $this->audit->write('identity.email_verified', (string) $locked->getKey(), $locked);
            $this->analytics->record('identity.email_verified', (string) $locked->getKey());

            return true;
        });
    }
}
