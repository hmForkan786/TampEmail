<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed for authenticated web requests when the principal is inactive.
 *
 * Complements login-time checks and API-key lifecycle gates: a suspended,
 * banned, pending, or soft-deleted user cannot keep using an existing session.
 */
final class EnsureActiveWebUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && (! $user->isActive() || $user->trashed())) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => __('This account cannot sign in.')]);
        }

        return $next($request);
    }
}
