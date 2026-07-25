<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Minimal session-based login for the user-facing outbound message pages.
 *
 * There is no self-service registration flow; accounts are provisioned
 * elsewhere (API-driven inbox/registration flows). This controller only
 * covers login/logout for an existing account.
 */
final class AuthenticatedSessionController extends Controller
{
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isActive() || $user->trashed()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __('This account cannot sign in.'),
            ]);
        }

        $request->session()->regenerate();

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
