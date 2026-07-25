<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundMessageData;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Submits outbound mail through an explicitly configured Laravel mailer.
 *
 * Fail-closed when the mailer is missing or submission throws.
 */
final class LaravelMailOutboundTransport implements OutboundTransportInterface
{
    public function __construct(
        private readonly string $mailer,
        private readonly string $providerName = 'mail',
    ) {}

    public function send(OutboundMessageData $message): OutboundDeliveryResult
    {
        $mailers = array_keys(config('mail.mailers', []));
        if (! in_array($this->mailer, $mailers, true)) {
            return OutboundDeliveryResult::permanentFailure(
                failureCode: 'invalid_config',
                failureMessage: 'Configured outbound mailer is unavailable.',
                provider: $this->providerName,
            );
        }

        try {
            $pending = Mail::mailer($this->mailer);

            $pending->html(
                $message->htmlBody ?? nl2br(e($message->textBody ?? '')),
                function (Message $mail) use ($message): void {
                    $mail->subject($message->subject);
                    if ($message->fromDisplayName !== null && $message->fromDisplayName !== '') {
                        $mail->from($message->fromAddress, $message->fromDisplayName);
                    } else {
                        $mail->from($message->fromAddress);
                    }

                    $mail->to($message->to);
                    if ($message->cc !== []) {
                        $mail->cc($message->cc);
                    }
                    if ($message->bcc !== []) {
                        $mail->bcc($message->bcc);
                    }
                    if ($message->textBody !== null) {
                        $mail->text($message->textBody);
                    }
                    if ($message->inReplyTo !== null) {
                        $mail->getHeaders()->addTextHeader('In-Reply-To', $message->inReplyTo);
                    }
                    if ($message->references !== null) {
                        $mail->getHeaders()->addTextHeader('References', $message->references);
                    }

                    foreach ($message->attachments as $attachment) {
                        $contents = Storage::disk($attachment['storage_disk'])
                            ->get($attachment['storage_path']);
                        $mail->attachData(
                            $contents,
                            $attachment['filename'],
                            ['mime' => $attachment['mime_type']],
                        );
                    }
                },
            );

            return OutboundDeliveryResult::accepted(
                provider: $this->providerName,
                providerMessageId: $message->messageId,
            );
        } catch (Throwable $exception) {
            $messageText = mb_strtolower($exception->getMessage());

            if (str_contains($messageText, 'timed out')
                || str_contains($messageText, 'timeout')
                || str_contains($messageText, 'connection refused')
                || str_contains($messageText, 'temporarily')) {
                return OutboundDeliveryResult::temporaryFailure(
                    failureCode: 'transport_temporary',
                    failureMessage: 'Temporary transport failure.',
                    provider: $this->providerName,
                );
            }

            return OutboundDeliveryResult::permanentFailure(
                failureCode: 'transport_error',
                failureMessage: 'Transport submission failed.',
                provider: $this->providerName,
            );
        }
    }
}
