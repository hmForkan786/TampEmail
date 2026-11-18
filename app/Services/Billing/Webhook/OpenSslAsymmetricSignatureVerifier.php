<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Contracts\Billing\AsymmetricSignatureVerifier;

final class OpenSslAsymmetricSignatureVerifier implements AsymmetricSignatureVerifier
{
    public function verify(string $algorithm, string $publicKey, string $payload, string $signature): bool
    {
        $opensslAlgorithm = match (strtoupper($algorithm)) {
            'RSA-SHA256', 'ECDSA-SHA256' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        if ($opensslAlgorithm === null || openssl_pkey_get_public($publicKey) === false) {
            return false;
        }

        return openssl_verify($payload, $signature, $publicKey, $opensslAlgorithm) === 1;
    }

    public function fingerprint(string $publicKey): ?string
    {
        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        return is_array($details) ? hash('sha256', $details['key']) : null;
    }
}
