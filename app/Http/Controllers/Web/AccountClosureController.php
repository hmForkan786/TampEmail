<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Identity\AccountClosureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class AccountClosureController extends Controller
{
    public function create(): View
    {
        return view('account.close', [
            'graceDays' => (int) config('identity.closure.grace_days', 7),
            'restoreSupported' => (bool) config('identity.closure.restore_supported', false),
        ]);
    }

    public function store(Request $request, AccountClosureService $closure): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        $ok = Hash::check((string) $request->input('password'), $user->password);
        $closure->requestClosure($user, $ok);

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('identityStatus', __('Your account has been closed.'));
    }
}
