<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OutboundNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class SendOutboundNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public string $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $notification = OutboundNotification::query()->with('user')->find($this->notificationId);
        if ($notification === null || $notification->email_sent_at !== null) {
            return;
        }

        $user = $notification->user;
        if (! $user instanceof User) {
            return;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = (array) $notification->payload;
            Mail::mailer((string) config('outbound_notifications.mailer'))->raw((string) ($payload['summary'] ?? 'An outbound message status changed.'), function ($message) use ($user, $notification): void {
                $message->to($user->email)->from((string) config('outbound_notifications.from_address'), (string) config('outbound_notifications.from_name'));
                $message->subject('Outbound status update: '.str_replace('_', ' ', (string) $notification->event_type));
            });
            $notification->forceFill(['email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Outbound notification email failed', ['notification_id' => $notification->id, 'event' => $notification->event_type]);
            throw $e;
        }
    }
}
