<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Validation\Rules\Password;

/**
 * Central password policy for registration and password reset.
 */
final class PasswordPolicy
{
    public static function rules(): Password
    {
        $rule = Password::min((int) config('identity.password.min_length', 12));

        if (config('identity.password.require_mixed_case', true) === true) {
            $rule = $rule->mixedCase();
        }

        if (config('identity.password.require_number', true) === true) {
            $rule = $rule->numbers();
        }

        if (config('identity.password.require_symbol', true) === true) {
            $rule = $rule->symbols();
        }

        if (config('identity.password.uncompromised_check', true) === true) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        return [
            'min_length' => (int) config('identity.password.min_length', 12),
            'require_mixed_case' => (bool) config('identity.password.require_mixed_case', true),
            'require_number' => (bool) config('identity.password.require_number', true),
            'require_symbol' => (bool) config('identity.password.require_symbol', true),
            'uncompromised_check' => (bool) config('identity.password.uncompromised_check', true),
        ];
    }
}
