<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdDevice;
use App\Exceptions\Ads\UnsafeAdContentException;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\AdImpression;
use App\Models\AdPlacement;
use App\Services\Ads\AdContentSanitizer;
use App\Services\Ads\AdDecisionEngine;
use App\Services\Ads\AdStatisticsService;
use App\Services\Ads\AdTargetingEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AdDecisionController extends Controller
{
    public function show(Request $request, string $placement, AdDecisionEngine $engine): JsonResponse
    {
        $device = AdDevice::tryFrom((string) $request->query('device', ''));
        $decision = $engine->decide(
            placementKey: $placement,
            user: Auth::guard('web')->user() ?? Auth::user(),
            country: $this->nullableString($request->query('country')),
            device: $device,
            language: $this->nullableString($request->query('language') ?? $request->getPreferredLanguage()),
            theme: $this->nullableString($request->query('theme')),
            sessionHash: $this->hashIdentifier($this->sessionId($request)),
            ipHash: $this->hashIdentifier((string) $request->ip()),
            recordImpression: $request->boolean('track', true),
        );

        return response()->json(['data' => $decision->toArray()]);
    }

    public function click(
        Request $request,
        AdTargetingEvaluator $targeting,
        AdStatisticsService $statistics,
        AdContentSanitizer $sanitizer,
    ): JsonResponse {
        $validated = $request->validate([
            'campaign_id' => ['required', 'uuid'],
            'placement_id' => ['required', 'uuid'],
            'impression_id' => ['nullable', 'uuid'],
            'destination_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $campaign = AdCampaign::query()->findOrFail($validated['campaign_id']);
        $placement = AdPlacement::query()->findOrFail($validated['placement_id']);
        $impression = isset($validated['impression_id'])
            ? AdImpression::query()->find($validated['impression_id'])
            : null;

        $destination = null;
        if (! empty($validated['destination_url'])) {
            try {
                $destination = $sanitizer->assertSafeUrl($validated['destination_url']);
            } catch (UnsafeAdContentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $device = AdDevice::tryFrom((string) $request->input('device', ''));
        $context = $targeting->buildContext(
            Auth::guard('web')->user() ?? Auth::user(),
            $this->nullableString($request->input('country')),
            $device,
            $this->nullableString($request->input('language')),
            $this->nullableString($request->input('theme')),
            $this->hashIdentifier($this->sessionId($request)),
            $this->hashIdentifier((string) $request->ip()),
        );

        $click = $statistics->recordClick($campaign, $placement, $context, $impression, $destination);

        return response()->json([
            'data' => [
                'click_id' => $click->getKey(),
                'destination_url' => $destination,
            ],
        ]);
    }

    private function sessionId(Request $request): string
    {
        try {
            return (string) $request->session()->getId();
        } catch (\Throwable) {
            return (string) $request->ip();
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function hashIdentifier(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return hash('sha256', $value);
    }
}
