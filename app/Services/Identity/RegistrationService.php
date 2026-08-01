<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\Identity\RegistrationChallengeVerifier;
use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Enums\PlatformRole;
use App\Enums\RegistrationMode;
use App\Enums\UserStatus;
use App\Models\IdentityPreference;
use App\Models\User;
use App\Services\Affiliates\AffiliateAttributionService;
use App\Services\Analytics\AnalyticsEventCollector;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Self-service registration with abuse protections and fail-closed modes.
 */
final class RegistrationService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly AnalyticsEventCollector $analytics,
        private readonly AffiliateAttributionService $affiliateAttribution,
        private readonly InviteService $invites,
        private readonly RegistrationChallengeVerifier $challengeVerifier,
        private readonly IdentityAnalyticsRecorder $identityAnalytics,
    ) {}

    public function mode(): RegistrationMode
    {
        return RegistrationMode::fromConfig((string) config('identity.registration.mode'));
    }

    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     terms_accepted?: bool,
     *     marketing_consent?: bool,
     *     locale?: string,
     *     timezone?: string,
     *     invite_token?: string|null,
     *     honeypot?: string|null,
     *     form_started_at?: int|null
     * }  $input
     */
    public function register(array $input, Request $request): ?User
    {
        $this->identityAnalytics->record('identity.registration_started');

        $mode = $this->mode();

        if ($mode === RegistrationMode::Disabled) {
            $this->audit->write('identity.registration_blocked', null, null, metadata: [
                'reason' => 'disabled',
            ]);
            throw ValidationException::withMessages([
                'email' => __('Registration is currently unavailable.'),
            ]);
        }

        if (! $this->challengeVerifier->verify($request)) {
            $this->audit->write('identity.registration_blocked', null, null, metadata: [
                'reason' => 'challenge_failed',
            ]);
            throw ValidationException::withMessages([
                'email' => __('Registration could not be completed. Please try again.'),
            ]);
        }

        $honeypotField = (string) config('identity.registration.honeypot_field', 'website');
        $honeypotValue = $input['honeypot'] ?? $request->input($honeypotField);
        if (config('identity.registration.honeypot_enabled', true) === true
            && is_string($honeypotValue)
            && trim($honeypotValue) !== ''
        ) {
            $this->audit->write('identity.registration_blocked', null, null, metadata: [
                'reason' => 'honeypot',
            ]);

            // Generic silent success — do not create a user.
            return null;
        }

        $startedAt = $input['form_started_at'] ?? $request->input('_form_started_at');
        $minMs = (int) config('identity.registration.min_form_fill_ms', 1500);
        if (is_numeric($startedAt) && $minMs > 0) {
            $elapsed = (int) (microtime(true) * 1000) - (int) $startedAt;
            if ($elapsed < $minMs) {
                $this->audit->write('identity.registration_blocked', null, null, metadata: [
                    'reason' => 'form_timing',
                ]);
                throw ValidationException::withMessages([
                    'email' => __('Registration could not be completed. Please try again.'),
                ]);
            }
        }

        $email = $this->normalizeEmail((string) $input['email']);
        $this->assertNotDisposable($email);

        if (config('identity.registration.terms_required', true) === true
            && empty($input['terms_accepted'])
        ) {
            throw ValidationException::withMessages([
                'terms_accepted' => __('You must accept the terms of service.'),
            ]);
        }

        $inviteToken = $mode === RegistrationMode::InviteOnly
            ? (string) ($input['invite_token'] ?? '')
            : null;

        $verificationRequired = (bool) config('identity.registration.email_verification_required', true);

        $cookieName = (string) config('affiliates.cookie.name', 'temail_aff');
        $visitorToken = $request->cookie($cookieName);

        $user = DB::transaction(function () use ($input, $email, $verificationRequired, $inviteToken, $visitorToken, $mode): User {
            $invite = null;
            if ($mode === RegistrationMode::InviteOnly) {
                $invite = $this->invites->consume((string) $inviteToken, $email);
            }

            if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('Unable to complete registration with the provided details.'),
                ]);
            }

            $user = new User;
            $user->forceFill([
                'name' => trim((string) $input['name']),
                'email' => $email,
                'password' => $input['password'],
                'locale' => $this->safeLocale($input['locale'] ?? null),
                'timezone' => $this->safeTimezone($input['timezone'] ?? null),
                'status' => $verificationRequired ? UserStatus::Pending : UserStatus::Active,
                'platform_role' => PlatformRole::User,
                'email_verified_at' => $verificationRequired ? null : now(),
                'terms_accepted_at' => now(),
                'marketing_consent_at' => ! empty($input['marketing_consent']) ? now() : null,
            ]);
            $user->save();

            IdentityPreference::query()->create([
                'user_id' => $user->getKey(),
                'terms_accepted' => true,
                'terms_accepted_at' => now(),
                'marketing_consent' => ! empty($input['marketing_consent']),
                'marketing_consent_at' => ! empty($input['marketing_consent']) ? now() : null,
            ]);

            if ($invite !== null) {
                $this->audit->write('identity.invite_used', (string) $user->getKey(), $invite, metadata: [
                    'invite_id' => (string) $invite->getKey(),
                ]);
            }

            $this->audit->write('identity.user_registered', (string) $user->getKey(), $user, metadata: [
                'verification_required' => $verificationRequired,
                'status' => $user->status->value,
                'default_plan' => (string) config('identity.registration.default_plan', 'free'),
            ]);

            DB::afterCommit(function () use ($user, $visitorToken, $verificationRequired): void {
                try {
                    $this->affiliateAttribution->linkUser($user, is_string($visitorToken) ? $visitorToken : null);
                } catch (Throwable) {
                    // Attribution failure must not corrupt registration.
                }

                try {
                    $this->analytics->record(
                        AnalyticsDomain::Users,
                        AnalyticsMetricKey::UsersRegistrations,
                        1,
                        ownerId: (string) $user->getKey(),
                        sourceEvent: 'identity.registration_completed',
                    );
                    $this->identityAnalytics->record('identity.registration_completed', (string) $user->getKey());
                } catch (Throwable) {
                    // Analytics fail-open.
                }

                if ($verificationRequired) {
                    try {
                        $user->sendEmailVerificationNotification();
                        $this->identityAnalytics->record('identity.email_verification_sent', (string) $user->getKey());
                        $this->audit->write('identity.email_verification_sent', (string) $user->getKey(), $user);
                    } catch (Throwable) {
                        // Delivery failures are retried via resend; registration already committed.
                    }
                }
            });

            return $user;
        });

        return $user;
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function assertNotDisposable(string $email): void
    {
        if (config('identity.registration.block_disposable_emails', false) !== true) {
            return;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $blocked = (array) config('identity.registration.disposable_domains', []);

        if ($domain !== '' && in_array($domain, $blocked, true)) {
            throw ValidationException::withMessages([
                'email' => __('Unable to complete registration with the provided details.'),
            ]);
        }
    }

    private function safeLocale(mixed $locale): string
    {
        $value = is_string($locale) ? trim($locale) : '';
        $supported = array_map('trim', explode(',', (string) config('app.supported_locales', 'en')));

        return in_array($value, $supported, true) ? $value : (string) config('app.locale', 'en');
    }

    private function safeTimezone(mixed $timezone): string
    {
        $value = is_string($timezone) ? trim($timezone) : '';

        return $value !== '' && in_array($value, timezone_identifiers_list(), true)
            ? $value
            : (string) config('app.timezone', 'UTC');
    }
}
