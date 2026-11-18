<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

interface AsymmetricSignatureVerifier
{
    public function verify(string $algorithm, string $publicKey, string $payload, string $signature): bool;

    public function fingerprint(string $publicKey): ?string;
}
