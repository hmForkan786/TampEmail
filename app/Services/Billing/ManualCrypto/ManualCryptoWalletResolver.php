<?php

declare(strict_types=1);

namespace App\Services\Billing\ManualCrypto;

use App\DTOs\Billing\ManualCryptoWallet;
use App\Exceptions\Billing\CheckoutException;

final class ManualCryptoWalletResolver
{
    public function resolve(): ManualCryptoWallet
    {
        $wallets = collect((array) config('billing.manual_crypto.wallets', []))
            ->filter(fn (array $wallet): bool => (bool) ($wallet['enabled'] ?? false))
            ->filter(fn (array $wallet): bool => strtoupper((string) ($wallet['network'] ?? '')) === 'TRC20')
            ->sortByDesc(fn (array $wallet): int => (int) ($wallet['priority'] ?? 0));

        $wallet = $wallets->first();
        $address = trim((string) ($wallet['address'] ?? ''));
        if (! is_array($wallet) || ! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            throw new CheckoutException('payment_gateway_unavailable', 'No enabled TRC20 wallet is available.', 422);
        }

        return new ManualCryptoWallet(
            (string) $wallet['id'],
            'TRC20',
            $address,
            (int) ($wallet['priority'] ?? 0),
            (string) ($wallet['rotation_group'] ?? 'default'),
        );
    }
}
