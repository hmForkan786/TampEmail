<?php

declare(strict_types=1);

namespace App\Contracts\Identity;

use Illuminate\Http\Request;

/**
 * Optional bot-challenge verifier (Turnstile/hCaptcha/reCAPTCHA adapters later).
 * Prompt 664 ships a disabled/no-op adapter only.
 */
interface RegistrationChallengeVerifier
{
    public function verify(Request $request): bool;
}
