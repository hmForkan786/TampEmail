<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithAffiliateProfile;
use App\Http\Controllers\Controller;
use App\Models\AffiliateCommissionEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AffiliateCommissionController extends Controller
{
    use InteractsWithAffiliateProfile;

    public function index(Request $request): JsonResponse
    {
        $profile = $this->requireProfile($this->affiliateUser($request));

        $paginator = AffiliateCommissionEntry::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->latest('created_at')
            ->latest('id')
            ->cursorPaginate(min(100, max(1, (int) $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AffiliateCommissionEntry $entry): array => $this->project($entry))->values(),
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
        ]);
    }

    /** @return array<string, mixed> */
    private function project(AffiliateCommissionEntry $entry): array
    {
        return [
            'id' => $entry->getKey(),
            'entry_type' => $entry->entry_type->value,
            'amount_minor' => $entry->amount_minor,
            'currency' => $entry->currency,
            'status' => $entry->status->value,
            'available_at' => $entry->available_at?->toIso8601String(),
            'reason_code' => $entry->reason_code,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
