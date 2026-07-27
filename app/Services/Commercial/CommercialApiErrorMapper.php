<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Exceptions\CommercialEntitlementDeniedException;
use App\Exceptions\OutboundSendException;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

/** Centralizes commercial entitlement error envelopes for API consumers. */
final class CommercialApiErrorMapper
{
    public static function featureDenied(string $feature, ?string $message = null, int $status = 403): JsonResponse
    {
        return ApiErrorResponse::make(
            'feature_not_available',
            $message ?? self::featureMessage($feature),
            $status,
            [
                'feature' => $feature,
                'upgrade_required' => true,
            ],
        );
    }

    public static function planLimitReached(string $feature, int $limit, int $used, ?string $message = null): JsonResponse
    {
        $remaining = max(0, $limit - $used);

        return ApiErrorResponse::make(
            'plan_limit_reached',
            $message ?? 'Your current plan limit has been reached.',
            409,
            [
                'feature' => $feature,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $remaining,
                'upgrade_required' => true,
            ],
        );
    }

    public static function rateLimitExceeded(string $feature, int $limit, int $remaining): JsonResponse
    {
        return ApiErrorResponse::make(
            'rate_limit_exceeded',
            'Too many API requests. Please try again later.',
            429,
            [
                'feature' => $feature,
                'limit' => $limit,
                'remaining' => $remaining,
                'upgrade_required' => true,
            ],
        );
    }

    public static function fromCommercialEntitlementDenied(CommercialEntitlementDeniedException $exception): JsonResponse
    {
        if ($exception->allowedLimit !== null) {
            return self::planLimitReached(
                $exception->feature,
                $exception->allowedLimit,
                $exception->currentValue ?? $exception->allowedLimit,
                $exception->getMessage(),
            );
        }

        return self::featureDenied($exception->feature, $exception->getMessage());
    }

    public static function fromOutboundSendException(OutboundSendException $exception, ?int $used = null, ?int $limit = null): JsonResponse
    {
        if ($exception->errorCode === 'plan_limit_reached' && $limit !== null && $used !== null) {
            return self::planLimitReached('webhook.max_endpoints', $limit, $used, $exception->getMessage());
        }

        if ($exception->errorCode === 'feature_not_available') {
            return self::featureDenied('webhook.access', $exception->getMessage(), $exception->status);
        }

        return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status);
    }

    private static function featureMessage(string $feature): string
    {
        return match ($feature) {
            'api.read' => 'Your current plan does not include API read access.',
            'api.write' => 'Your current plan does not include API write access.',
            'webhook.access' => 'Your current plan does not include webhook access.',
            default => 'Your current plan does not include this API capability.',
        };
    }
}
