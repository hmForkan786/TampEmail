<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;
use App\Models\Email;

final class OutboundReplyRecipientResolver
{
    public function __construct(
        private readonly OutboundRecipientValidator $recipients,
    ) {}

    /**
     * Derive the primary reply recipient from Reply-To (preferred) or From.
     */
    public function resolve(Email $email): string
    {
        $replyTo = $this->headerAddress($email, ['reply-to', 'Reply-To', 'REPLY-TO']);

        if ($replyTo !== null) {
            try {
                $normalized = $this->recipients->normalizeAddress($replyTo, 'reply_to');
                $this->assertNotNullReturnPath($normalized);

                return $normalized;
            } catch (OutboundSendException) {
                // Invalid Reply-To is skipped; fall back to sender per contract.
            }
        }

        $sender = trim((string) $email->sender_email);
        if ($sender === '') {
            throw new OutboundSendException('reply_sender_missing', 'The original message has no usable sender address.', 422);
        }

        $normalized = $this->recipients->normalizeAddress($sender, 'sender');
        $this->assertNotNullReturnPath($normalized);

        return $normalized;
    }

    private function assertNotNullReturnPath(string $address): void
    {
        $local = strstr($address, '@', true) ?: $address;

        if ($address === '<>' || $local === '' || str_starts_with($local, 'mailer-daemon')) {
            throw new OutboundSendException('null_return_path', 'The original message has an unsafe automated return path.', 422);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function headerAddress(Email $email, array $keys): ?string
    {
        $headers = is_array($email->headers) ? $email->headers : [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $headers)) {
                continue;
            }

            $value = $headers[$key];
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
                return trim($matches[1]);
            }

            return trim($value);
        }

        return null;
    }
}
