<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithAffiliateProfile;
use App\Http\Controllers\Controller;
use App\Models\AffiliateConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AffiliateConversionController extends Controller
{
    use InteractsWithAffiliateProfile;

    public function index(Request $request): JsonResponse
    {
        $profile = $this->requireProfile($this->affiliateUser($request));

        $paginator = AffiliateConversion::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->with('referredUser')
            ->latest('qualified_at')
            ->latest('id')
            ->cursorPaginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AffiliateConversion $conversion): array => $this->project($conversion))->values(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    /** @return array<string, mixed> */
    private function project(AffiliateConversion $conversion): array
    {
        return [
            'id' => $conversion->getKey(),
            'status' => $conversion->status->value,
            'order_amount_minor' => $conversion->order_amount_minor,
            'commission_amount_minor' => $conversion->commission_amount_minor,
            'currency' => $conversion->currency,
            'referred_user_email' => $this->maskEmail($conversion->referredUser->email),
            'qualified_at' => $conversion->qualified_at->toIso8601String(),
            'approved_at' => $conversion->approved_at?->toIso8601String(),
            'rejected_at' => $conversion->rejected_at?->toIso8601String(),
            'reversed_at' => $conversion->reversed_at?->toIso8601String(),
            'reason_code' => $conversion->reason_code,
        ];
    }
}
