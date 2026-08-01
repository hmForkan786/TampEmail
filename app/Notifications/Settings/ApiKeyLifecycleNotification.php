<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApiKeyLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $action,
        private readonly string $keyName,
        private readonly string $prefix,
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
            ->subject(__('API key :action', ['action' => $this->action]))
            ->line(__('An API key named ":name" (:prefix…) was :action from Settings.', [
                'name' => $this->keyName,
                'prefix' => $this->prefix,
                'action' => $this->action,
            ]))
            ->line(__('If you did not perform this action, revoke keys and change your password immediately.'));
    }
}
