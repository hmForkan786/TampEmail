<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Exceptions\ApiKeyQuotaExceededException;
use App\Exceptions\CommercialEntitlementDeniedException;
use App\Exceptions\OutboundSendException;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;
use Illuminate\Http\JsonResponse;

/** Unified commercial denial envelopes for API and UI consumers. */
final class CommercialResponseFactory
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly CommercialQuotaResolver $quota,
    ) {}

    public function featureUnavailable(string $feature, ?string $message = null, int $status = 403, ?User $user = null): JsonResponse
    {
        return ApiErrorResponse::make(
            'feature_not_available',
            $message ?? $this->featureMessage($feature),
            $status,
            array_merge(
                ['feature' => $feature],
                $this->upgradeDetails($user, true),
            ),
        );
    }

    public function planLimitReached(string $feature, int $limit, int $used, ?string $message = null, int $status = 409, ?User $user = null): JsonResponse
    {
        return ApiErrorResponse::make(
            'plan_limit_reached',
            $message ?? 'Your current plan limit has been reached.',
            $status,
            array_merge(
                [
                    'feature' => $feature,
                    'limit' => $limit,
                    'used' => $used,
                    'remaining' => $this->quota->remaining($limit, $used),
                ],
                $this->upgradeDetails($user, true),
            ),
        );
    }

    public function rateLimitExceeded(string $feature, int $limit, int $remaining, ?User $user = null): JsonResponse
    {
        return ApiErrorResponse::make(
            'rate_limit_exceeded',
            'Too many API requests. Please try again later.',
            429,
            array_merge(
                [
                    'feature' => $feature,
                    'limit' => $limit,
                    'remaining' => max(0, $remaining),
                ],
                $this->upgradeDetails($user, true),
            ),
        );
    }

    public function fromCommercialEntitlementDenied(CommercialEntitlementDeniedException $exception, ?User $user = null): JsonResponse
    {
        if ($exception->allowedLimit !== null) {
            return $this->planLimitReached(
                $exception->feature,
                $exception->allowedLimit,
                $exception->currentValue ?? $exception->allowedLimit,
                $exception->getMessage(),
                403,
                $user,
            );
        }

        return $this->featureUnavailable($exception->feature, $exception->getMessage(), 403, $user);
    }

    public function fromApiKeyQuotaExceeded(ApiKeyQuotaExceededException $exception, ?User $user = null): JsonResponse
    {
        if ($exception->limit !== null && $exception->used !== null) {
            return $this->planLimitReached(
                'max_api_keys',
                $exception->limit,
                $exception->used,
                $exception->getMessage(),
                409,
                $user,
            );
        }

        return ApiErrorResponse::make('plan_limit_reached', $exception->getMessage(), 409);
    }

    public function fromOutboundSendException(OutboundSendException $exception, ?User $user = null, ?int $used = null, ?int $limit = null, ?string $feature = null): JsonResponse
    {
        if ($exception->errorCode === 'plan_limit_reached') {
            $featureKey = $feature ?? $this->inferFeatureFromMessage($exception->getMessage());
            $used ??= $featureKey !== null && $user !== null
                ? $this->quota->resolveUsed($user, $featureKey, $this->featureKind($featureKey))
                : 0;
            $limit ??= $featureKey !== null && $user !== null
                ? ($this->quota->resolveLimit($user, $featureKey) ?? 0)
                : 0;

            if ($limit === PHP_INT_MAX) {
                $limit = $used;
            }

            return $this->planLimitReached(
                $featureKey ?? 'plan_limit',
                max(0, $limit),
                max(0, $used),
                $exception->getMessage(),
                $exception->status,
                $user,
            );
        }

        if ($exception->errorCode === 'feature_not_available') {
            $featureKey = $feature ?? $this->inferFeatureFromMessage($exception->getMessage()) ?? 'feature_not_available';

            return $this->featureUnavailable($featureKey, $exception->getMessage(), $exception->status, $user);
        }

        return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status);
    }

    /** @return array{upgrade_required: bool, recommended_plan: string|null} */
    private function upgradeDetails(?User $user, bool $required): array
    {
        if (! $required) {
            return ['upgrade_required' => false, 'recommended_plan' => null];
        }

        $recommended = (string) config('commercial.recommended_plan_slug', 'premium');
        if ($user === null) {
            return ['upgrade_required' => true, 'recommended_plan' => $recommended];
        }

        $plan = $this->entitlements->effectivePlan($user);
        if ($plan !== null && $plan->slug === $recommended) {
            return ['upgrade_required' => false, 'recommended_plan' => null];
        }

        return ['upgrade_required' => true, 'recommended_plan' => $recommended];
    }

    private function featureMessage(string $feature): string
    {
        return match ($feature) {
            'api.read' => 'Your current plan does not include API read access.',
            'api.write' => 'Your current plan does not include API write access.',
            'webhook.access' => 'Your current plan does not include webhook access.',
            'inbox.create' => 'Your current plan does not include inbox creation.',
            'inbox.custom_alias' => 'Your current plan does not include custom inbox aliases.',
            'send_email', 'reply_email', 'forward_email' => 'Your current plan does not include this outbound feature.',
            default => 'Your current plan does not include this API capability.',
        };
    }

    private function inferFeatureFromMessage(string $message): ?string
    {
        return match (true) {
            str_contains(strtolower($message), 'webhook endpoint') => 'webhook.max_endpoints',
            str_contains(strtolower($message), 'webhook') => 'webhook.access',
            str_contains(strtolower($message), 'outbound message quota') => 'outbound_messages_per_period',
            str_contains(strtolower($message), 'sender profile') => 'outbound.sender_profiles',
            default => null,
        };
    }

    private function featureKind(string $featureKey): string
    {
        $configured = (array) config('commercial.summary_features', []);

        return is_string($configured[$featureKey] ?? null) ? $configured[$featureKey] : 'inventory';
    }
}
