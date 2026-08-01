<?php

declare(strict_types=1);

namespace App\Notifications\Identity;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NewLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $deviceSummary,
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
            ->subject(__('New sign-in to your account'))
            ->line(__('A new sign-in was detected on your account.'))
            ->line(__('Device: :device', ['device' => $this->deviceSummary]))
            ->line(__('If this was not you, reset your password and revoke other sessions.'));
    }
}
