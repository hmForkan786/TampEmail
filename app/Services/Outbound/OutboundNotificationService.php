<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Jobs\SendOutboundNotificationEmailJob;
use App\Models\OutboundMessage;
use App\Models\OutboundNotification;
use App\Models\OutboundNotificationPreference;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class OutboundNotificationService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function preference(User $user): OutboundNotificationPreference
    {
        return OutboundNotificationPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'notifications_enabled' => true,
                'in_app_enabled' => true,
                'email_enabled' => true,
                'events' => config('outbound_notifications.defaults'),
                'version' => 1,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function notify(User $user, string $event, ?OutboundMessage $message = null, array $extra = [], ?string $key = null): ?OutboundNotification
    {
        if (! in_array($event, config('outbound_notifications.events'), true) || ! $user->isActive()) {
            return null;
        }
        $pref = $this->preference($user);
        $events = (array) ($pref->events ?? []);
        $rules = (array) ($events[$event] ?? config('outbound_notifications.defaults.'.$event, []));
        if (! $pref->notifications_enabled
            || (
                (! $pref->in_app_enabled || ! ($rules['in_app'] ?? false))
                && (! $pref->email_enabled || ! ($rules['email'] ?? false))
            )) {
            Cache::increment('outbound.metrics.notifications_suppressed');

            return null;
        }
        $payload = ['event_type' => $event, 'operation' => $message?->operation?->value, 'state' => $message?->state?->value, 'outbound_message_uuid' => $message?->id, 'failure_category' => $extra['failure_category'] ?? null, 'retryable' => $extra['retryable'] ?? null, 'scheduled_at' => $extra['scheduled_at'] ?? $message?->scheduled_at?->toIso8601String(), 'percentage' => $extra['percentage'] ?? null, 'summary' => $this->summary($event)];
        $messageId = $message !== null ? $message->id : 'usage';
        $messageStamp = $message !== null && $message->updated_at !== null
            ? $message->updated_at->timestamp
            : now()->timestamp;
        $idempotency = $key ?? implode(':', [$event, $messageId, $extra['period'] ?? $messageStamp]);

        return DB::transaction(function () use ($user, $event, $message, $payload, $idempotency, $pref, $rules): OutboundNotification {
            $notification = OutboundNotification::query()->firstOrCreate(['user_id' => $user->id, 'idempotency_key' => $idempotency], ['outbound_message_id' => $message?->id, 'event_type' => $event, 'payload' => $payload]);
            if (! $notification->wasRecentlyCreated) {
                Cache::increment('outbound.metrics.notification_duplicates');

                return $notification;
            }
            $this->audit->write('outbound.notification_created', $user->id, $notification, null, null, ['notification_uuid' => $notification->id, 'event_type' => $event, 'outbound_uuid' => $message?->id]);
            if ($pref->email_enabled && ($rules['email'] ?? false)) {
                $notification->forceFill(['email_queued_at' => now()])->save();
                SendOutboundNotificationEmailJob::dispatch($notification->id)->afterCommit();
                $this->audit->write('outbound.notification_email_queued', $user->id, $notification, null, null, ['notification_uuid' => $notification->id, 'event_type' => $event, 'channel' => 'email']);
            }
            Cache::increment('outbound.metrics.notifications_created');

            return $notification;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updatePreference(User $user, array $input, int $version): OutboundNotificationPreference
    {
        $pref = $this->preference($user);
        if ($pref->version !== $version) {
            throw new \RuntimeException('stale_notification_preference');
        }
        $allowed = ['notifications_enabled', 'in_app_enabled', 'email_enabled', 'events'];
        $values = array_intersect_key($input, array_flip($allowed));
        if (isset($values['events'])) {
            foreach ($values['events'] as $event => $channels) {
                if (! in_array($event, config('outbound_notifications.events'), true) || ! is_array($channels) || array_diff(array_keys($channels), ['in_app', 'email'])) {
                    throw new \InvalidArgumentException('invalid_notification_preference');
                }
            } $values['events'] = array_replace($pref->events ?? [], $values['events']);
        }
        $updated = OutboundNotificationPreference::query()->whereKey($pref->id)->where('version', $version)->update([...$values, 'version' => $version + 1, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new \RuntimeException('stale_notification_preference');
        }
        $fresh = $pref->fresh();
        $this->audit->write('outbound.notification_preferences_updated', $user->id, $fresh, null, null, ['preference_version' => $fresh->version]);

        return $fresh;
    }

    private function summary(string $event): string
    {
        return match ($event) {
            'outbound.failed' => 'Your outbound message failed before delivery.','outbound.schedule_failed' => 'Your scheduled message could not be dispatched.','outbound.usage_warning' => 'You are approaching your outbound message allowance.','outbound.usage_exhausted' => 'Your outbound message allowance is exhausted.','outbound.delivered' => 'Your outbound message was delivered.',default => 'Your outbound message status was updated.'
        };
    }
}
