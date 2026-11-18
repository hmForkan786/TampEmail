<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\DTOs\Billing\CallbackIngestionResult;
use Symfony\Component\HttpFoundation\Response;

interface ProviderCallbackResponseFormatter
{
    public function provider(): string;

    public function accepted(CallbackIngestionResult $result): Response;

    public function rejected(int $status): Response;
}
