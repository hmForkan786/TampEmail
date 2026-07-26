<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\OutboundNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboundNotificationController
{
    public function index(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('apiKeyOwner');
        $q = OutboundNotification::query()->where('user_id', $owner->id)->whereNull('dismissed_at')->latest();
        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        } if ($request->filled('event_type')) {
            $q->where('event_type', $request->string('event_type'));
        }

        return response()->json(['data' => $q->paginate(min(100, max(1, $request->integer('per_page', 25))))]);
    }

    public function count(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('apiKeyOwner');

        return response()->json(['data' => ['unread_count' => OutboundNotification::query()->where('user_id', $owner->id)->whereNull('read_at')->whereNull('dismissed_at')->count()]]);
    }

    public function show(Request $request, string $notification): JsonResponse
    {
        return response()->json(['data' => $this->owned($request, $notification)]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $n = $this->owned($request, $notification);
        $n->forceFill(['read_at' => $n->read_at ?? now()])->save();

        return response()->json(['data' => $n]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('apiKeyOwner');
        $count = OutboundNotification::query()->where('user_id', $owner->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $n = $this->owned($request, $notification);
        $n->forceFill(['dismissed_at' => $n->dismissed_at ?? now()])->save();

        return response()->json(['data' => ['dismissed' => true]]);
    }

    private function owned(Request $request, string $id): OutboundNotification
    {
        $owner = $request->attributes->get('apiKeyOwner');

        return OutboundNotification::query()->where('user_id', $owner->id)->whereKey($id)->firstOrFail();
    }
}
