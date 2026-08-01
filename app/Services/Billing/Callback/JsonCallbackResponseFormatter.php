<?php

declare(strict_types=1);

namespace App\Services\Billing\Callback;

use App\Contracts\Billing\ProviderCallbackResponseFormatter;
use App\DTOs\Billing\CallbackIngestionResult;
use Symfony\Component\HttpFoundation\Response;

final class JsonCallbackResponseFormatter implements ProviderCallbackResponseFormatter
{
    public function provider(): string
    {
        return '*';
    }

    public function accepted(CallbackIngestionResult $result): Response
    {
        return response()->json(['accepted' => $result->accepted, 'duplicate' => $result->duplicate, 'event_id' => $result->internalEventId, 'status' => $result->processingStatus], 202);
    }

    public function rejected(int $status): Response
    {
        return response()->json(['accepted' => false, 'code' => 'invalid_webhook_signature'], $status);
    }
}
