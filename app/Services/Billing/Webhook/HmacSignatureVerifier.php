<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Enums\SignatureEncoding;
use InvalidArgumentException;

final class HmacSignatureVerifier
{
    public function verify(string $algorithm, string $secret, string $canonicalPayload, string $providedSignature, SignatureEncoding $encoding): bool
    {
        $algorithm = strtolower($algorithm);
        if (! in_array($algorithm, ['sha256', 'sha512'], true)) {
            throw new InvalidArgumentException('Unsupported HMAC algorithm.');
        }
        if ($secret === '') {
            throw new InvalidArgumentException('Signing secret is not configured.');
        }

        $provided = $this->decode($providedSignature, $encoding);
        if ($provided === null) {
            return false;
        }

        return hash_equals(hash_hmac($algorithm, $canonicalPayload, $secret, true), $provided);
    }

    private function decode(string $value, SignatureEncoding $encoding): ?string
    {
        return match ($encoding) {
            SignatureEncoding::Hex => preg_match('/\A(?:[0-9a-f]{2})+\z/D', $value) === 1 ? hex2bin($value) ?: null : null,
            SignatureEncoding::Base64 => base64_decode($value, true) ?: null,
            SignatureEncoding::Base64Url => $this->decodeBase64Url($value),
            SignatureEncoding::Raw => null,
        };
    }

    private function decodeBase64Url(string $value): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
