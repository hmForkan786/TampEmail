<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\User;
use App\Notifications\Identity\PasswordChangedNotification;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use App\Services\Identity\EmailChangeService;
use App\Services\Identity\PasswordPolicy;
use App\Services\Identity\SessionManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password and email-security settings, reusing Identity services.
 */
final class UserSecuritySettingsService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly SessionManagementService $sessions,
        private readonly EmailChangeService $emailChange,
        private readonly ApiKeyRepositoryInterface $apiKeys,
        private readonly SettingsAnalyticsRecorder $analytics,
    ) {}

    public function changePassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?string $currentSessionId = null,
    ): User {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Choose a password that is different from your current password.'),
            ]);
        }

        return DB::transaction(function () use ($user, $newPassword, $currentSessionId): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'password' => $newPassword,
                'remember_token' => Str::random(60),
            ])->save();

            if (config('settings.password_change.revoke_other_sessions', true) === true && is_string($currentSessionId)) {
                $this->sessions->revokeAllForUser($locked, exceptSessionId: $currentSessionId);
            }

            if (config('settings.password_change.revoke_api_keys', false) === true) {
                $this->apiKeys->revokeAllUnrevokedForUser((string) $locked->getKey(), now());
            }

            $this->audit->write('settings.password_changed', (string) $locked->getKey(), $locked, metadata: [
                'other_sessions_revoked' => (bool) config('settings.password_change.revoke_other_sessions', true),
                'api_keys_revoked' => (bool) config('settings.password_change.revoke_api_keys', false),
            ]);
            $this->analytics->record('settings.security_action_completed', (string) $locked->getKey(), dimensions: [
                'action' => 'password_changed',
            ]);

            $locked->notify(new PasswordChangedNotification);

            return $locked->fresh() ?? $locked;
        });
    }

    public function requestEmailChange(User $user, string $newEmail): User
    {
        $updated = $this->emailChange->stagePendingEmail($user, $newEmail, $user);

        $this->audit->write('settings.email_change_requested', (string) $updated->getKey(), $updated, metadata: [
            'pending_email_set' => true,
        ]);
        $this->analytics->record('settings.security_action_completed', (string) $updated->getKey(), dimensions: [
            'action' => 'email_change_requested',
        ]);

        return $updated;
    }

    public function cancelEmailChange(User $user): User
    {
        $updated = $this->emailChange->cancelPendingEmail($user, $user);

        $this->audit->write('settings.email_change_cancelled', (string) $updated->getKey(), $updated, metadata: [
            'pending_email_cleared' => true,
        ]);

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function passwordPolicySummary(): array
    {
        return PasswordPolicy::summary();
    }
}
