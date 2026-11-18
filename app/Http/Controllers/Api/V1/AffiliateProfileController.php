<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AffiliatePayoutMethod;
use App\Exceptions\Affiliates\AffiliateException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithAffiliateProfile;
use App\Http\Controllers\Controller;
use App\Models\AffiliateProfile;
use App\Services\Affiliates\AffiliateDashboardService;
use App\Services\Affiliates\AffiliateRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AffiliateProfileController extends Controller
{
    use InteractsWithAffiliateProfile;

    public function __construct(
        private readonly AffiliateRegistrationService $registration,
        private readonly AffiliateDashboardService $dashboard,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $profile = $this->requireProfile($this->affiliateUser($request));

        return response()->json(['data' => $this->projectProfile($profile)]);
    }

    public function apply(Request $request): JsonResponse
    {
        $user = $this->affiliateUser($request);

        $validated = Validator::make($request->all(), [
            'promotion_channel' => ['nullable', 'string', 'max:100'],
            'website_url' => ['nullable', 'string', 'max:255', 'url'],
            'audience_description' => ['nullable', 'string', 'max:2000'],
            'expected_traffic' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payout_method' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (AffiliatePayoutMethod $m): string => $m->value, AffiliatePayoutMethod::cases()))],
            'payout_details' => ['nullable', 'string', 'max:2000', 'required_with:payout_method'],
        ])->validate();

        try {
            $profile = $this->registration->apply($user, [
                'promotion_channel' => $validated['promotion_channel'] ?? null,
                'website_url' => $validated['website_url'] ?? null,
                'audience_description' => $validated['audience_description'] ?? null,
                'expected_traffic' => $validated['expected_traffic'] ?? null,
                'application_notes' => $validated['notes'] ?? null,
            ]);
        } catch (AffiliateException $exception) {
            return $this->affiliateErrorResponse($exception);
        }

        if (isset($validated['payout_method'])) {
            $profile->forceFill([
                'payout_method' => $validated['payout_method'],
                'payout_details_encrypted' => $validated['payout_details'] ?? $profile->payout_details_encrypted,
            ])->save();
        }

        return response()->json(['data' => $this->projectProfile($profile->refresh())], 201);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $profile = $this->requireProfile($this->affiliateUser($request));

        return response()->json(['data' => $this->dashboard->forProfile($profile)]);
    }

    /** @return array<string, mixed> */
    private function projectProfile(AffiliateProfile $profile): array
    {
        return [
            'id' => $profile->getKey(),
            'affiliate_code' => $profile->affiliate_code,
            'status' => $profile->status->value,
            'payout_method' => $profile->payout_method?->value,
            'promotion_channel' => $profile->promotion_channel,
            'website_url' => $profile->website_url,
            'audience_description' => $profile->audience_description,
            'expected_traffic' => $profile->expected_traffic,
            'application_notes' => $profile->application_notes,
            'approved_at' => $profile->approved_at?->toIso8601String(),
            'suspended_at' => $profile->suspended_at?->toIso8601String(),
            'rejected_at' => $profile->rejected_at?->toIso8601String(),
            'closed_at' => $profile->closed_at?->toIso8601String(),
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }
}
