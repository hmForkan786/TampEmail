<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Notifications\Identity\EmailChangedNotification;
use App\Notifications\Identity\VerifyPendingEmailNotification;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Staged email change: never overwrite email before signed verification of the new address.
 */
final class EmailChangeService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SessionManagementService $sessions,
        private readonly IdentityAnalyticsRecorder $analytics,
    ) {}

    public function stagePendingEmail(User $user, string $newEmail, ?User $actor = null): User
    {
        $newEmail = strtolower(trim($newEmail));

        if ($newEmail === '' || $newEmail === strtolower($user->email)) {
            throw ValidationException::withMessages([
                'email' => __('A different valid email address is required.'),
            ]);
        }

        return DB::transaction(function () use ($user, $newEmail, $actor): User {
            $exists = User::query()
                ->where('email', $newEmail)
                ->whereKeyNot($user->getKey())
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'email' => __('Unable to use the requested email address.'),
                ]);
            }

            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'pending_email' => $newEmail,
                'pending_email_verified_at' => null,
            ])->save();

            $this->audit->write('identity.email_change_requested', $actor?->getKey() ? (string) $actor->getKey() : (string) $locked->getKey(), $locked, metadata: [
                // Never log the raw new email.
                'pending_email_set' => true,
            ]);

            Notification::route('mail', $newEmail)
                ->notify(new VerifyPendingEmailNotification(
                    $this->signedPendingEmailUrl($locked->fresh() ?? $locked),
                ));

            return $locked->fresh() ?? $locked;
        });
    }

    public function confirmPendingEmail(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $pending = $locked->pending_email;
            if (! is_string($pending) || $pending === '') {
                throw ValidationException::withMessages([
                    'email' => __('There is no pending email change to confirm.'),
                ]);
            }

            $pending = strtolower(trim($pending));

            $conflict = User::query()
                ->where('email', $pending)
                ->whereKeyNot($locked->getKey())
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'email' => __('Unable to use the requested email address.'),
                ]);
            }

            $oldEmail = $locked->email;
            $locked->forceFill([
                'email' => $pending,
                'pending_email' => null,
                'pending_email_verified_at' => now(),
                'email_verified_at' => now(),
            ])->save();

            $this->sessions->revokeAllForUser($locked);

            $this->audit->write('identity.email_changed', (string) $locked->getKey(), $locked, metadata: [
                'sessions_revoked' => true,
            ]);
            $this->analytics->record('identity.email_changed', (string) $locked->getKey());

            $locked->notify(new EmailChangedNotification);
            Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification);

            return $locked->fresh() ?? $locked;
        });
    }

    public function cancelPendingEmail(User $user, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! is_string($locked->pending_email) || $locked->pending_email === '') {
                return $locked;
            }

            $locked->forceFill([
                'pending_email' => null,
                'pending_email_verified_at' => null,
            ])->save();

            $this->audit->write(
                'identity.email_change_cancelled',
                $actor?->getKey() ? (string) $actor->getKey() : (string) $locked->getKey(),
                $locked,
                metadata: ['pending_email_cleared' => true],
            );

            return $locked->fresh() ?? $locked;
        });
    }

    public function signedPendingEmailUrl(User $user): string
    {
        $minutes = (int) config('identity.email_verification.expire_minutes', 60);

        return URL::temporarySignedRoute(
            'account.pending-email.verify',
            now()->addMinutes($minutes),
            [
                'id' => $user->getKey(),
                'hash' => sha1((string) $user->pending_email),
            ],
        );
    }
}
