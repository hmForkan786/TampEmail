<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PrivacyExportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $exportId) {}

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
            ->subject(__('Your data export is ready'))
            ->line(__('Your privacy export request is ready to download from Settings → Privacy.'))
            ->line(__('Export id: :id', ['id' => $this->exportId]))
            ->line(__('The download link expires automatically for security.'));
    }
}
