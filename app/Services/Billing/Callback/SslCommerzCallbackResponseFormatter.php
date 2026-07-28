<?php

declare(strict_types=1);

namespace App\Services\Billing\Callback;

use App\Contracts\Billing\ProviderCallbackResponseFormatter;
use App\DTOs\Billing\CallbackIngestionResult;
use Symfony\Component\HttpFoundation\Response;

final class SslCommerzCallbackResponseFormatter implements ProviderCallbackResponseFormatter
{
    public function provider(): string
    {
        return 'sslcommerz';
    }

    public function accepted(CallbackIngestionResult $result): Response
    {
        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    public function rejected(int $status): Response
    {
        return response('REJECTED', $status)->header('Content-Type', 'text/plain');
    }
}
