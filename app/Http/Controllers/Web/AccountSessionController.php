<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\SessionManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class AccountSessionController extends Controller
{
    public function index(Request $request, SessionManagementService $sessions): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.sessions', [
            'sessions' => $sessions->listForUser($user, $request->session()->getId()),
            'enumerationSupported' => $sessions->supportsEnumeration(),
        ]);
    }

    public function destroy(Request $request, string $sessionId, SessionManagementService $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        $ok = Hash::check((string) $request->input('password'), $user->password);
        $sessions->revokeOne($user, $sessionId, (string) $request->session()->getId(), $ok);

        return back()->with('identityStatus', __('Session revoked.'));
    }

    public function destroyOthers(Request $request, SessionManagementService $sessions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        $ok = Hash::check((string) $request->input('password'), $user->password);
        $count = $sessions->revokeOthers($user, (string) $request->session()->getId(), $ok);

        return back()->with('identityStatus', __(':count other session(s) revoked.', ['count' => $count]));
    }
}
