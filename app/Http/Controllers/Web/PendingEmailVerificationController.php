<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PendingEmailVerificationController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash, EmailChangeService $emailChange): RedirectResponse
    {
        $user = User::query()->whereKey($id)->first();

        if (! $user instanceof User || ! is_string($user->pending_email) || $user->pending_email === '') {
            return redirect()->route('login')->withErrors(['email' => __('Invalid verification link.')]);
        }

        if (! hash_equals(sha1($user->pending_email), $hash)) {
            return redirect()->route('login')->withErrors(['email' => __('Invalid verification link.')]);
        }

        $emailChange->confirmPendingEmail($user);

        return redirect()->route('login')
            ->with('identityStatus', __('Your email address has been updated. Please sign in.'));
    }
}
