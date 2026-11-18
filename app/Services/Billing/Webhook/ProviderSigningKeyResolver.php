<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\Models\ProviderSigningKey;

final class ProviderSigningKeyResolver
{
    /** @return list<array{id:string, algorithm:string, secret:string}> */
    public function resolve(string $provider, string $environment): array
    {
        $maximum = (int) config('billing.webhook_security.max_candidate_signing_keys', 3);
        $keys = ProviderSigningKey::query()
            ->where('provider', $provider)->where('environment', $environment)
            ->whereIn('status', ['active', 'retiring'])
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->limit($maximum)->get()
            ->map(fn (ProviderSigningKey $key): array => ['id' => $key->key_id, 'algorithm' => $key->algorithm, 'secret' => (string) $key->secret_encrypted])
            ->filter(fn (array $key): bool => $key['secret'] !== '')->values()->all();

        $configured = (string) config("billing.webhook_security.providers.{$provider}.secret", '');
        if ($configured !== '' && count($keys) < $maximum) {
            $keys[] = ['id' => (string) config("billing.webhook_security.providers.{$provider}.key_id", 'config-v1'), 'algorithm' => (string) config("billing.webhook_security.providers.{$provider}.algorithm", 'sha256'), 'secret' => $configured];
        }

        return $keys;
    }
}
