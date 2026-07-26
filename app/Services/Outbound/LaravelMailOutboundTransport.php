<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundMessageData;
use App\Exceptions\OutboundSendException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Throwable;

/**
 * Submits outbound mail through an explicitly configured Laravel mailer / SMTP path.
 *
 * Fail-closed on missing configuration, unsafe headers, or missing attachments.
 * Never logs bodies, BCC recipients, or credentials.
 */
final class LaravelMailOutboundTransport implements OutboundTransportInterface
{
    public function __construct(
        private readonly string $mailer,
        private readonly string $providerName = 'mail',
        private readonly ?OutboundHeaderGuard $headers = null,
        private readonly ?OutboundTransportFailureMapper $failures = null,
        private readonly ?OutboundTransportConfigValidator $configValidator = null,
    ) {}

    public function send(OutboundMessageData $message): OutboundDeliveryResult
    {
        $headers = $this->headers ?? app(OutboundHeaderGuard::class);
        $failures = $this->failures ?? app(OutboundTransportFailureMapper::class);
        $configValidator = $this->configValidator ?? app(OutboundTransportConfigValidator::class);

        $mailers = array_keys(config('mail.mailers', []));
        if ($this->mailer === '' || ! in_array($this->mailer, $mailers, true)) {
            return OutboundDeliveryResult::configurationFailure(
                failureCode: 'invalid_mailer',
                failureMessage: 'Configured outbound mailer is unavailable.',
                provider: $this->providerName,
            );
        }

        if (in_array($this->providerName, ['smtp', 'mail'], true)) {
            $validation = $configValidator->validate($this->providerName === 'mail' ? 'mail' : 'smtp');
            if (! $validation['valid']) {
                return OutboundDeliveryResult::configurationFailure(
                    failureCode: $validation['failure_code'] ?? 'invalid_config',
                    failureMessage: 'Outbound SMTP configuration is invalid.',
                    provider: $this->providerName,
                );
            }
        }

        $tempFiles = [];

        try {
            $envelope = $headers->sanitizeEnvelope(
                fromAddress: $message->fromAddress,
                fromDisplayName: $message->fromDisplayName,
                subject: $message->subject,
                outboundMessageId: $message->messageId,
                inReplyTo: $message->inReplyTo,
                references: $message->references,
                localDomain: (string) (config('outbound.smtp.local_domain') ?: ''),
                replyToAddress: $message->replyToAddress,
                replyToName: $message->replyToName,
            );

            foreach ($message->to as $recipient) {
                $headers->assertSafeEmail($recipient, 'to');
            }
            foreach ($message->cc as $recipient) {
                $headers->assertSafeEmail($recipient, 'cc');
            }
            foreach ($message->bcc as $recipient) {
                $headers->assertSafeEmail($recipient, 'bcc');
            }

            $pending = Mail::mailer($this->mailer);
            $html = $message->htmlBody ?? nl2br(e($message->textBody ?? ''));

            $pending->html(
                $html,
                function (Message $mail) use ($message, $envelope, &$tempFiles): void {
                    $mail->subject($envelope['subject']);
                    if ($envelope['from_display_name'] !== null) {
                        $mail->from($envelope['from_address'], $envelope['from_display_name']);
                    } else {
                        $mail->from($envelope['from_address']);
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

                    if ($envelope['reply_to_address'] !== null) {
                        if ($envelope['reply_to_name'] !== null) {
                            $mail->replyTo($envelope['reply_to_address'], $envelope['reply_to_name']);
                        } else {
                            $mail->replyTo($envelope['reply_to_address']);
                        }
                    }

                    $symfony = $mail->getSymfonyMessage();
                    $this->setProtectedIdHeader($symfony, 'Message-ID', $envelope['message_id']);
                    if ($envelope['in_reply_to'] !== null) {
                        $this->setProtectedIdHeader($symfony, 'In-Reply-To', $envelope['in_reply_to']);
                    }
                    if ($envelope['references'] !== null) {
                        $this->setProtectedIdHeader($symfony, 'References', $envelope['references']);
                    }

                    foreach ($message->attachments as $attachment) {
                        $this->attachSafely($mail, $attachment, $tempFiles);
                    }
                },
            );

            return OutboundDeliveryResult::accepted(
                provider: $this->providerName,
                providerMessageId: $envelope['message_id'],
            );
        } catch (OutboundSendException $exception) {
            $code = $exception->errorCode;
            if (in_array($code, ['attachment_unavailable', 'attachment_missing', 'attachment_unsafe', 'attachment_too_large', 'attachments_total_too_large'], true)) {
                return OutboundDeliveryResult::permanentFailure(
                    failureCode: $code,
                    failureMessage: 'Attachment validation failed before submission.',
                    provider: $this->providerName,
                );
            }

            return OutboundDeliveryResult::permanentFailure(
                failureCode: $code !== '' ? $code : 'invalid_message',
                failureMessage: 'Outbound message construction failed.',
                provider: $this->providerName,
            );
        } catch (Throwable $exception) {
            return $failures->map($exception, $this->providerName);
        } finally {
            foreach ($tempFiles as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function setProtectedIdHeader(SymfonyEmail $email, string $name, string $value): void
    {
        $headerBag = $email->getHeaders();
        if ($headerBag->has($name)) {
            $headerBag->remove($name);
        }

        $ids = preg_split('/\s+/', trim($value)) ?: [];
        $ids = array_values(array_filter(array_map(
            static fn (string $id): string => trim($id, "<> \t"),
            $ids,
        )));

        if ($ids === []) {
            return;
        }

        $headerBag->addIdHeader($name, count($ids) === 1 ? $ids[0] : $ids);
    }

    /**
     * @param  array{filename: string, mime_type: string, size_bytes: int, storage_disk: string, storage_path: string}  $attachment
     * @param  list<string>  $tempFiles
     */
    private function attachSafely(Message $mail, array $attachment, array &$tempFiles): void
    {
        $diskName = (string) ($attachment['storage_disk'] ?? '');
        $path = (string) ($attachment['storage_path'] ?? '');
        $filename = (string) ($attachment['filename'] ?? 'attachment.bin');
        $mime = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
        $size = (int) ($attachment['size_bytes'] ?? 0);

        if ($diskName === '' || $path === '') {
            throw new OutboundSendException('attachment_missing', 'Attachment storage metadata is incomplete.', 422);
        }

        $maxFile = (int) config('outbound.max_attachment_bytes', 10485760);
        if ($size <= 0 || $size > $maxFile) {
            throw new OutboundSendException('attachment_too_large', 'Attachment exceeds the configured size limit.', 422);
        }

        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            throw new OutboundSendException('attachment_unavailable', 'Attachment object is missing from private storage.', 422);
        }

        $options = ['as' => $filename, 'mime' => $mime];

        // Prefer path-based attach for local disks to avoid loading full bytes when possible.
        try {
            $localPath = $disk->path($path);
            if (is_string($localPath) && is_file($localPath)) {
                $mail->attach($localPath, $options);

                return;
            }
        } catch (Throwable) {
            // Non-local disks throw; fall through to streamed temp file.
        }

        $stream = $disk->readStream($path);
        if ($stream === false) {
            throw new OutboundSendException('attachment_unavailable', 'Attachment object could not be opened.', 422);
        }

        $temp = tempnam(sys_get_temp_dir(), 'outbound_att_');
        if ($temp === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new OutboundSendException('attachment_unavailable', 'Unable to stage attachment for submission.', 422);
        }

        $target = fopen($temp, 'wb');
        if ($target === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temp);
            throw new OutboundSendException('attachment_unavailable', 'Unable to stage attachment for submission.', 422);
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $written = filesize($temp);
        if ($written === false || $written <= 0 || $written > $maxFile) {
            @unlink($temp);
            throw new OutboundSendException('attachment_too_large', 'Attachment exceeds the configured size limit.', 422);
        }

        $tempFiles[] = $temp;
        $mail->attach($temp, $options);
    }
}
