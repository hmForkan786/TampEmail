<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ManualCryptoCheckoutSnapshot;
use App\Services\Billing\ManualCrypto\ManualCryptoAmount;
use Illuminate\Http\JsonResponse;

final class ManualCryptoInstructionController extends Controller
{
    public function __invoke(ManualCryptoCheckoutSnapshot $snapshot): JsonResponse
    {
        abort_if($snapshot->expires_at->isPast(), 410);

        return response()->json(['data' => [
            'checkout_reference' => $snapshot->getKey(),
            'asset' => $snapshot->asset,
            'network' => $snapshot->network,
            'wallet_address' => $snapshot->wallet_address,
            'expected_amount' => ManualCryptoAmount::format(ManualCryptoAmount::expectedUnits($snapshot->expected_amount_minor)),
            'order_currency' => $snapshot->currency,
            'expires_at' => $snapshot->expires_at->toIso8601String(),
            'notice' => 'Submit only USDT on TRON/TRC20. Payment remains unverified until authorized manual review.',
        ]]);
    }
}
