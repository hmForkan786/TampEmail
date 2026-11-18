<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Models\AffiliateProfile;

/**
 * Generates unique, uppercase alphanumeric affiliate referral codes.
 */
final class AffiliateCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private const LENGTH = 8;

    private const MAX_ATTEMPTS = 20;

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->randomCode();

            if (! AffiliateProfile::query()->where('affiliate_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique affiliate code.');
    }

    private function randomCode(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
