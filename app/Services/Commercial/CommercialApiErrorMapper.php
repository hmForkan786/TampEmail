<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Exceptions\CommercialEntitlementDeniedException;
use App\Exceptions\OutboundSendException;
use Illuminate\Http\JsonResponse;

/** Backwards-compatible static entrypoints delegating to CommercialResponseFactory. */
final class CommercialApiErrorMapper
{
    public static function featureDenied(string $feature, ?string $message = null, int $status = 403): JsonResponse
    {
        return app(CommercialResponseFactory::class)->featureUnavailable($feature, $message, $status);
    }

    public static function planLimitReached(string $feature, int $limit, int $used, ?string $message = null): JsonResponse
    {
        return app(CommercialResponseFactory::class)->planLimitReached($feature, $limit, $used, $message);
    }

    public static function rateLimitExceeded(string $feature, int $limit, int $remaining): JsonResponse
    {
        return app(CommercialResponseFactory::class)->rateLimitExceeded($feature, $limit, $remaining);
    }

    public static function fromCommercialEntitlementDenied(CommercialEntitlementDeniedException $exception): JsonResponse
    {
        return app(CommercialResponseFactory::class)->fromCommercialEntitlementDenied($exception);
    }

    public static function fromOutboundSendException(OutboundSendException $exception, ?int $used = null, ?int $limit = null): JsonResponse
    {
        return app(CommercialResponseFactory::class)->fromOutboundSendException($exception, null, $used, $limit);
    }
}
