<?php

declare(strict_types=1);

namespace App\Notifications\Identity;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountClosureNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $grace = (int) config('identity.closure.grace_days', 7);

        return (new MailMessage)
            ->subject(__('Account closure confirmation'))
            ->line(__('Your account has been closed.'))
            ->line(__('Billing, invoice, audit, and affiliate financial records are retained per policy.'))
            ->line($grace > 0
                ? __('A grace period of :days day(s) applies. Self-service restore is not supported.', ['days' => $grace])
                : __('Self-service restore is not supported.'));
    }
}
