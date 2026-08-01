<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed for authenticated web requests when the principal is blocked.
 *
 * Allows Active and Pending (email verification / limited account surfaces).
 * Suspended, banned, closed, and soft-deleted users are logged out.
 * Product features that require a fully verified active account should also
 * apply Laravel's `verified` middleware and/or status checks.
 */
final class EnsureActiveWebUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && (! $user->mayAuthenticate() || $user->trashed())) {
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
