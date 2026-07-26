<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Outbound\OutboundNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboundNotificationPreferenceController
{
    public function __construct(private readonly OutboundNotificationService $service) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->preference($request->attributes->get('apiKeyOwner'))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'notifications_enabled' => ['sometimes', 'boolean'], 'in_app_enabled' => ['sometimes', 'boolean'], 'email_enabled' => ['sometimes', 'boolean'], 'events' => ['sometimes', 'array']]);
        try {
            return response()->json(['data' => $this->service->updatePreference($request->attributes->get('apiKeyOwner'), $data, $data['version'])]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Notification preferences have changed. Refresh and try again.'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'The given data was invalid.'], 422);
        }
    }
}
