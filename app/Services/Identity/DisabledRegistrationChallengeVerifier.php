<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\Identity\RegistrationChallengeVerifier;
use Illuminate\Http\Request;

/**
 * Default challenge verifier: disabled → pass; enabled without provider → fail closed.
 */
final class DisabledRegistrationChallengeVerifier implements RegistrationChallengeVerifier
{
    public function verify(Request $request): bool
    {
        if (config('identity.challenge.enabled', false) !== true) {
            return true;
        }

        $provider = strtolower((string) config('identity.challenge.provider', 'none'));

        // No Turnstile/hCaptcha/reCAPTCHA adapter in Prompt 664.
        return false;
    }
}
