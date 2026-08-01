<?php

declare(strict_types=1);

namespace App\Notifications\Identity;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SessionsRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $count,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Sessions revoked'))
            ->line(__(':count other session(s) on your account were revoked.', ['count' => $this->count]));
    }
}
