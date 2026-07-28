<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\Billing\CheckoutException;
use App\Exceptions\Billing\DisabledPaymentProviderException;
use App\Exceptions\Billing\UnknownPaymentProviderException;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

final class BillingResponseFactory
{
    public function fromThrowable(\Throwable $exception): JsonResponse
    {
        if ($exception instanceof CheckoutException) {
            return ApiErrorResponse::make($exception->errorCode, $this->message($exception->errorCode), $exception->status, $exception->details);
        }
        if ($exception instanceof UnknownPaymentProviderException || $exception instanceof DisabledPaymentProviderException) {
            return ApiErrorResponse::make('payment_gateway_unavailable', 'The selected payment gateway is unavailable.', 422);
        }

        return ApiErrorResponse::make('billing_unavailable', 'Billing is temporarily unavailable.', 503);
    }

    private function message(string $code): string
    {
        return match ($code) {
            'plan_not_available' => 'The selected plan is not available for checkout.',
            'idempotency_conflict' => 'The idempotency key was already used with different checkout data.',
            'checkout_in_progress' => 'A checkout is already in progress.',
            'invalid_checkout_redirect' => 'The checkout redirect is not allowed.',
            'checkout_expired' => 'The checkout has expired.',
            'order_already_paid' => 'The billing order has already been paid.',
            default => 'The checkout request could not be completed.',
        };
    }
}
