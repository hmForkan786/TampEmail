<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Notifications\Identity\PasswordChangedNotification;
use App\Notifications\Identity\ResetPasswordNotification;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password reset request/completion with session and optional API-key rotation.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly IdentityAnalyticsRecorder $analytics,
        private readonly SessionManagementService $sessions,
        private readonly ApiKeyRepositoryInterface $apiKeys,
    ) {}

    /**
     * Always returns a generic success-oriented status string for enumeration resistance.
     */
    public function sendResetLink(string $email): string
    {
        $email = strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();

        $this->analytics->record('identity.password_reset_requested', $user instanceof User ? (string) $user->getKey() : null);
        $this->audit->write('identity.password_reset_requested', $user instanceof User ? (string) $user->getKey() : null, $user, metadata: [
            'account_found' => $user instanceof User,
            // Do not disclose blocked status to callers; still record internally without PII.
            'may_reset' => $user instanceof User && ! $user->status->isBlocked() && ! $user->trashed(),
        ]);

        if (! $user instanceof User || $user->trashed() || $user->status->isBlocked()) {
            // Generic response — do not send mail for blocked/missing accounts.
            return Password::RESET_LINK_SENT;
        }

        $status = Password::broker()->sendResetLink(['email' => $email], function (User $user, string $token): void {
            $user->notify(new ResetPasswordNotification($token));
        });

        // Always present as sent to anonymous callers.
        return Password::RESET_LINK_SENT;
    }

    /**
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $credentials
     */
    public function reset(array $credentials, ?Request $request = null): string
    {
        $status = Password::broker()->reset(
            $credentials,
            function (User $user, string $password) use ($request): void {
                if ($user->status->isBlocked() || $user->trashed()) {
                    throw ValidationException::withMessages([
                        'email' => __('Unable to reset the password for this account.'),
                    ]);
                }

                DB::transaction(function () use ($user, $password, $request): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    if (config('identity.password_reset.revoke_sessions', true) === true) {
                        $this->sessions->revokeAllForUser($user, exceptSessionId: $request?->session()->getId());
                    }

                    if (config('identity.password_reset.revoke_api_keys', false) === true) {
                        $this->apiKeys->revokeAllUnrevokedForUser((string) $user->getKey(), now());
                    }

                    $this->audit->write('identity.password_reset_completed', (string) $user->getKey(), $user, metadata: [
                        'sessions_revoked' => (bool) config('identity.password_reset.revoke_sessions', true),
                        'api_keys_revoked' => (bool) config('identity.password_reset.revoke_api_keys', false),
                        'status_unchanged' => $user->status->value,
                    ]);
                    $this->analytics->record('identity.password_reset_completed', (string) $user->getKey());
                    $this->analytics->record('identity.session_revoked', (string) $user->getKey());
                });

                event(new PasswordReset($user));
                $user->notify(new PasswordChangedNotification);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return $status;
    }
}
