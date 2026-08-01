<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny product features until the user is Active and email-verified.
 */
final class EnsureVerifiedActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->hasVerifiedEmail() || ! $user->isActive()) {
            return redirect()
                ->route('verification.notice')
                ->with('identityStatus', __('Please verify your email address to continue.'));
        }

        return $next($request);
    }
}
