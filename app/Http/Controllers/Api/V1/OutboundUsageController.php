<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Safe, user-visible outbound subscription usage snapshot. Never exposes
 * abuse thresholds (see OutboundRateLimiter) — entitlement/quota only.
 */
final class OutboundUsageController
{
    public function __construct(
        private readonly OutboundUsageService $usage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        return response()->json(['data' => $this->usage->summaryForUser($owner)]);
    }
}
