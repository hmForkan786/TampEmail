<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Identity\NewLoginNotification;
use App\Services\Audit\AuditLogWriter;
use App\Services\Identity\IdentityAnalyticsRecorder;
use App\Services\Identity\LoginAttemptRecorder;
use App\Services\Identity\SessionManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session-based login/logout with status checks, audit, and analytics.
 */
final class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly LoginAttemptRecorder $loginAttempts,
        private readonly IdentityAnalyticsRecorder $analytics,
        private readonly AuditLogWriter $audit,
        private readonly SessionManagementService $sessions,
    ) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($credentials['email']));
        $remember = $request->boolean('remember')
            && config('identity.sessions.remember_me_enabled', true) === true;

        $user = User::query()->where('email', $email)->first();

        if (! Auth::attempt(['email' => $email, 'password' => $credentials['password']], $remember)) {
            $this->loginAttempts->record($request, $email, false, $user, 'invalid_credentials');
            $this->analytics->record('identity.login_failed', $user instanceof User ? (string) $user->getKey() : null);
            $this->audit->write('identity.login_failed', $user instanceof User ? (string) $user->getKey() : null, $user, metadata: [
                'reason' => 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        /** @var User $authenticated */
        $authenticated = Auth::user();

        if (! $authenticated->mayAuthenticate() || $authenticated->trashed()) {
            Auth::logout();
            $this->loginAttempts->record($request, $email, false, $authenticated, 'blocked_status');
            $this->analytics->record('identity.login_failed', (string) $authenticated->getKey());
            $this->audit->write('identity.login_failed', (string) $authenticated->getKey(), $authenticated, metadata: [
                'reason' => 'blocked_status',
            ]);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        $authenticated->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->loginAttempts->record($request, $email, true, $authenticated);
        $this->analytics->record('identity.login_succeeded', (string) $authenticated->getKey());
        $this->audit->write('identity.login_succeeded', (string) $authenticated->getKey(), $authenticated);

        DB::transaction(function () use ($authenticated, $request): void {
            $this->sessions->enforceLimitAfterLogin($authenticated, (string) $request->session()->getId());
        });

        $authenticated->notify(new NewLoginNotification(
            Str::limit((string) $request->userAgent(), 120, '…') ?: 'Unknown device',
        ));

        if (! $authenticated->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('outbound-messages.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
