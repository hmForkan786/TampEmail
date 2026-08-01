<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Identity\EmailVerificationService;
use App\Services\Identity\IdentityAnalyticsRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail() && $user->isActive()) {
            return redirect()->intended(route('outbound-messages.index'));
        }

        return view('auth.verify-email');
    }

    public function verify(Request $request, string $id, string $hash, EmailVerificationService $service): RedirectResponse
    {
        /** @var User|null $user */
        $user = User::query()->whereKey($id)->first();

        if (! $user instanceof User) {
            return redirect()->route('login')->withErrors(['email' => __('Invalid verification link.')]);
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            app(AuditLogWriter::class)->write('identity.verification_failed', (string) $user->getKey(), $user, metadata: [
                'reason' => 'hash_mismatch',
            ]);

            return redirect()->route('login')->withErrors(['email' => __('Invalid verification link.')]);
        }

        try {
            $service->markVerified($user);
        } catch (AuthorizationException) {
            return redirect()->route('login')->withErrors(['email' => __('These credentials do not match our records.')]);
        }

        if ($request->user()?->getKey() !== $user->getKey()) {
            auth()->login($user);
            $request->session()->regenerate();
        }

        return redirect()->intended(route('outbound-messages.index'))
            ->with('identityStatus', __('Email verified successfully.'));
    }

    public function resend(Request $request, IdentityAnalyticsRecorder $analytics, AuditLogWriter $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('outbound-messages.index'));
        }

        $user->sendEmailVerificationNotification();
        $analytics->record('identity.email_verification_sent', (string) $user->getKey());
        $audit->write('identity.email_verification_sent', (string) $user->getKey(), $user);

        return back()->with('identityStatus', __('If verification is required, a new link has been sent when eligible.'));
    }
}
