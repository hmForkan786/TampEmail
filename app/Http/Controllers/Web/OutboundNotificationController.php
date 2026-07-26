<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OutboundNotification;
use App\Services\Outbound\OutboundNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OutboundNotificationController extends Controller
{
    public function __construct(private readonly OutboundNotificationService $service) {}

    public function index(Request $request): View
    {
        $q = OutboundNotification::query()->where('user_id', $request->user()->id)->whereNull('dismissed_at')->latest();
        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        }

        return view('outbound-notifications.index', ['notifications' => $q->paginate(25)]);
    }

    public function preferences(Request $request): View
    {
        return view('outbound-notifications.preferences', ['preference' => $this->service->preference($request->user()), 'events' => config('outbound_notifications.events')]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $n = OutboundNotification::query()->where('user_id', $request->user()->id)->findOrFail($notification);
        $n->forceFill(['read_at' => $n->read_at ?? now()])->save();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        OutboundNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $n = OutboundNotification::query()->where('user_id', $request->user()->id)->findOrFail($notification);
        $n->forceFill(['dismissed_at' => $n->dismissed_at ?? now()])->save();

        return back()->with('outboundStatus', 'Notification dismissed.');
    }
}
