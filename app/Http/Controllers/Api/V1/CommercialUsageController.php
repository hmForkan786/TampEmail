<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Commercial\CommercialUsageSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner-scoped commercial usage and remaining quota summary. */
final class CommercialUsageController
{
    public function __construct(
        private readonly CommercialUsageSummaryService $summary,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        return response()->json(['data' => $this->summary->forUser($owner)]);
    }
}
