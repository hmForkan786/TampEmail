<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Identity\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request, PasswordResetService $service): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $service->sendResetLink((string) $request->input('email'));

        return back()->with('identityStatus', __('If an account exists for that email, a reset link has been sent when eligible.'));
    }
}
