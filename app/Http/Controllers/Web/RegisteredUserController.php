<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\RegistrationMode;
use App\Http\Controllers\Controller;
use App\Services\Identity\PasswordPolicy;
use App\Services\Identity\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class RegisteredUserController extends Controller
{
    public function create(RegistrationService $registration): View|RedirectResponse
    {
        $mode = $registration->mode();

        if ($mode === RegistrationMode::Disabled) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Registration is currently unavailable.')]);
        }

        return view('auth.register', [
            'mode' => $mode,
            'formStartedAt' => (int) (microtime(true) * 1000),
            'honeypotField' => (string) config('identity.registration.honeypot_field', 'website'),
        ]);
    }

    public function store(Request $request, RegistrationService $registration): RedirectResponse
    {
        $mode = $registration->mode();

        if ($mode === RegistrationMode::Disabled) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Registration is currently unavailable.')]);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordPolicy::rules()],
            'terms_accepted' => config('identity.registration.terms_required', true)
                ? ['accepted']
                : ['sometimes', 'boolean'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:16'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'invite_token' => $mode === RegistrationMode::InviteOnly
                ? ['required', 'string', 'min:32', 'max:128']
                : ['sometimes', 'nullable', 'string', 'max:128'],
            '_form_started_at' => ['sometimes', 'nullable', 'integer'],
        ];

        $honeypot = (string) config('identity.registration.honeypot_field', 'website');
        $rules[$honeypot] = ['sometimes', 'nullable', 'string', 'max:255'];

        // Reject privileged field injection by ignoring them entirely (not in rules / never passed through).
        $validated = $request->validate($rules);

        $user = $registration->register([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'terms_accepted' => (bool) ($validated['terms_accepted'] ?? false),
            'marketing_consent' => (bool) ($validated['marketing_consent'] ?? false),
            'locale' => $validated['locale'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
            'invite_token' => $validated['invite_token'] ?? null,
            'honeypot' => $request->input($honeypot),
            'form_started_at' => isset($validated['_form_started_at']) ? (int) $validated['_form_started_at'] : null,
        ], $request);

        if ($user === null) {
            // Honeypot silent success.
            return redirect()
                ->route('login')
                ->with('identityStatus', __('If registration is available, check your email for next steps.'));
        }

        Auth::login($user);
        $request->session()->regenerate();

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('outbound-messages.index'));
    }
}
