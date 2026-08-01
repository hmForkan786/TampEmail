<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\Identity\AccountClosureNotification;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account closure foundation. Preserves billing/audit/affiliate financial records.
 */
final class AccountClosureService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SessionManagementService $sessions,
        private readonly ApiKeyRepositoryInterface $apiKeys,
        private readonly IdentityAnalyticsRecorder $analytics,
    ) {}

    public function requestClosure(User $user, bool $passwordConfirmed): User
    {
        if (! $passwordConfirmed) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }

        if ($user->status === UserStatus::Closed) {
            return $user;
        }

        if ($user->status->isBlocked()) {
            throw ValidationException::withMessages([
                'account' => __('This account cannot request closure in its current state.'),
            ]);
        }

        $graceDays = max(0, (int) config('identity.closure.grace_days', 7));

        return DB::transaction(function () use ($user, $graceDays): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => UserStatus::Closed,
                'closed_at' => now(),
                'closure_scheduled_for' => $graceDays > 0 ? now()->addDays($graceDays) : now(),
                'remember_token' => null,
            ])->save();

            $this->sessions->revokeAllForUser($locked);
            $this->apiKeys->revokeAllUnrevokedForUser((string) $locked->getKey(), now());

            $this->audit->write('identity.account_closure_requested', (string) $locked->getKey(), $locked, metadata: [
                'grace_days' => $graceDays,
                'restore_supported' => (bool) config('identity.closure.restore_supported', false),
            ]);
            $this->audit->write('identity.account_closed', (string) $locked->getKey(), $locked);
            $this->analytics->record('identity.account_closure_requested', (string) $locked->getKey());

            $locked->notify(new AccountClosureNotification);

            return $locked->fresh() ?? $locked;
        });
    }
}
