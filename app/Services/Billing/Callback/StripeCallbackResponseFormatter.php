<?php

declare(strict_types=1);

namespace App\Services\Billing\Callback;

use App\Contracts\Billing\ProviderCallbackResponseFormatter;
use App\DTOs\Billing\CallbackIngestionResult;
use Symfony\Component\HttpFoundation\Response;

final class StripeCallbackResponseFormatter implements ProviderCallbackResponseFormatter
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function accepted(CallbackIngestionResult $result): Response
    {
        return response()->json(['received' => true], 200);
    }

    public function rejected(int $status): Response
    {
        return response()->json(['received' => false], $status);
    }
}
