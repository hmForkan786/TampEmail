<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountRecoveryStatus;
use App\Enums\RegistrationMode;
use App\Enums\UserStatus;
use App\Models\AccountRecoveryRequest;
use App\Models\LoginAttempt;
use App\Models\RegistrationInvite;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Operational health snapshot for identity:health.
 *
 * @phpstan-type CheckResult array{name: string, ok: bool, detail: string}
 */
final class IdentityHealthCheckService
{
    /**
     * @return array{ok: bool, checks: list<CheckResult>, metrics: array<string, int|string|bool>}
     */
    public function check(): array
    {
        $mode = RegistrationMode::fromConfig((string) config('identity.registration.mode'));
        $modeRaw = strtolower(trim((string) config('identity.registration.mode')));
        $modeKnown = RegistrationMode::tryFrom($modeRaw) !== null;

        $checks = [
            [
                'name' => 'registration_mode_valid',
                'ok' => $modeKnown,
                'detail' => $modeKnown
                    ? 'mode='.$mode->value
                    : 'unknown mode fails closed to disabled (effective='.$mode->value.')',
            ],
            [
                'name' => 'password_broker_configured',
                'ok' => config('auth.defaults.passwords') === 'users'
                    && is_array(config('auth.passwords.users')),
                'detail' => 'broker='.(string) config('auth.defaults.passwords'),
            ],
            [
                'name' => 'verification_route_configured',
                'ok' => true,
                'detail' => 'signed verification routes expected under /email/verify',
            ],
            [
                'name' => 'notification_queue_configured',
                'ok' => (string) config('queue.default') !== '',
                'detail' => 'default_queue='.(string) config('queue.default'),
            ],
            [
                'name' => 'session_driver_compatibility',
                'ok' => true,
                'detail' => 'driver='.(string) config('session.driver')
                    .'; enumeration='.(config('session.driver') === 'database' ? 'yes' : 'limited'),
            ],
            [
                'name' => 'identity_tables_present',
                'ok' => Schema::hasTable('login_attempts')
                    && Schema::hasTable('registration_invites')
                    && Schema::hasTable('account_recovery_requests'),
                'detail' => 'identity migrations applied',
            ],
            [
                'name' => 'hash_key_configured',
                'ok' => (string) config('identity.hash_key') !== '',
                'detail' => 'IDENTITY_HASH_KEY or APP_KEY present',
            ],
        ];

        $stalePending = User::query()
            ->where('status', UserStatus::Pending)
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDay())
            ->count();

        $staleRecovery = AccountRecoveryRequest::query()
            ->whereIn('status', [
                AccountRecoveryStatus::Submitted->value,
                AccountRecoveryStatus::UnderReview->value,
                AccountRecoveryStatus::Approved->value,
            ])
            ->where('created_at', '<', now()->subHours((int) config('identity.recovery.expire_hours', 72)))
            ->count();

        $failedLogins = LoginAttempt::query()
            ->where('success', false)
            ->where('occurred_at', '>=', now()->subHour())
            ->count();

        $inviteBacklog = RegistrationInvite::query()
            ->whereNull('revoked_at')
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereColumn('uses', '<', 'max_uses')
            ->count();

        $ok = ! in_array(false, array_column($checks, 'ok'), true);

        return [
            'ok' => $ok,
            'checks' => $checks,
            'metrics' => [
                'registration_mode' => $mode->value,
                'verification_required' => (bool) config('identity.registration.email_verification_required', true),
                'identity_verification_pending' => User::query()->where('status', UserStatus::Pending)->whereNull('email_verified_at')->count(),
                'identity_verification_pending_stale' => $stalePending,
                'identity_recovery_requests_open' => AccountRecoveryRequest::query()->whereNotIn('status', [
                    AccountRecoveryStatus::Completed->value,
                    AccountRecoveryStatus::Rejected->value,
                    AccountRecoveryStatus::Cancelled->value,
                ])->count(),
                'identity_recovery_stale' => $staleRecovery,
                'identity_login_failure_last_hour' => $failedLogins,
                'identity_invite_backlog' => $inviteBacklog,
                'max_active_web_sessions' => (int) config('identity.sessions.max_active_web_sessions', 0),
                'prune_enabled' => (bool) config('identity.prune.enabled', false),
            ],
        ];
    }
}
