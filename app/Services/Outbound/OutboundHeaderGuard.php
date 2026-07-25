<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;

/**
 * Validates and normalizes protected outbound header values before transport submission.
 *
 * Never accepts arbitrary client-supplied header maps.
 */
final class OutboundHeaderGuard
{
    private const MAX_MESSAGE_ID_LENGTH = 998;

    private const MAX_REFERENCES_LENGTH = 4000;

    private const MAX_SUBJECT_LENGTH = 998;

    private const MAX_DISPLAY_NAME_LENGTH = 255;

    /**
     * @return array{
     *     from_address: string,
     *     from_display_name: string|null,
     *     subject: string,
     *     message_id: string,
     *     in_reply_to: string|null,
     *     references: string|null
     * }
     */
    public function sanitizeEnvelope(
        string $fromAddress,
        ?string $fromDisplayName,
        string $subject,
        string $outboundMessageId,
        ?string $inReplyTo,
        ?string $references,
        ?string $localDomain = null,
    ): array {
        $fromAddress = $this->assertSafeEmail($fromAddress, 'from');
        $display = $fromDisplayName !== null && trim($fromDisplayName) !== ''
            ? $this->assertSafeHeaderValue(trim($fromDisplayName), 'from_display_name', self::MAX_DISPLAY_NAME_LENGTH)
            : null;
        $subject = $this->assertSafeHeaderValue($subject, 'subject', self::MAX_SUBJECT_LENGTH);
        $messageId = $this->buildMessageId($outboundMessageId, $localDomain);
        $replyTo = $inReplyTo !== null && trim($inReplyTo) !== ''
            ? $this->normalizeMessageId($inReplyTo)
            : null;
        $refs = $references !== null && trim($references) !== ''
            ? $this->normalizeReferences($references)
            : null;

        return [
            'from_address' => $fromAddress,
            'from_display_name' => $display,
            'subject' => $subject,
            'message_id' => $messageId,
            'in_reply_to' => $replyTo,
            'references' => $refs,
        ];
    }

    public function assertSafeEmail(string $address, string $field): string
    {
        $address = $this->assertSafeHeaderValue($address, $field, 320);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new OutboundSendException('invalid_'.$field, "The {$field} address is invalid.", 422);
        }

        return strtolower($address);
    }

    public function buildMessageId(string $outboundMessageId, ?string $localDomain = null): string
    {
        $id = preg_replace('/[^A-Za-z0-9._-]/', '', $outboundMessageId) ?: 'outbound';
        $domain = $this->safeLocalDomain($localDomain);

        return $this->normalizeMessageId($id.'@'.$domain);
    }

    public function normalizeMessageId(string $messageId): string
    {
        $messageId = $this->stripControls($messageId);
        $messageId = trim($messageId);
        if ($messageId === '') {
            throw new OutboundSendException('invalid_message_id', 'The message id is invalid.', 422);
        }

        if (! str_starts_with($messageId, '<')) {
            $messageId = '<'.$messageId;
        }
        if (! str_ends_with($messageId, '>')) {
            $messageId .= '>';
        }

        if (preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/', $messageId) !== 1) {
            throw new OutboundSendException('invalid_message_id', 'The message id is invalid.', 422);
        }

        return mb_substr($messageId, 0, self::MAX_MESSAGE_ID_LENGTH);
    }

    public function normalizeReferences(string $references): string
    {
        $references = $this->stripControls($references);
        $references = preg_replace('/\s+/', ' ', trim($references)) ?? '';
        if ($references === '') {
            throw new OutboundSendException('invalid_references', 'The references header is invalid.', 422);
        }

        $parts = preg_split('/\s+/', $references) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $normalized[] = $this->normalizeMessageId($part);
        }

        $joined = implode(' ', $normalized);
        if (mb_strlen($joined) > self::MAX_REFERENCES_LENGTH) {
            // Keep the newest (rightmost) identifiers within the cap.
            while (mb_strlen($joined) > self::MAX_REFERENCES_LENGTH && count($normalized) > 1) {
                array_shift($normalized);
                $joined = implode(' ', $normalized);
            }
            $joined = mb_substr($joined, 0, self::MAX_REFERENCES_LENGTH);
        }

        return $joined;
    }

    private function assertSafeHeaderValue(string $value, string $field, int $maxLength): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new OutboundSendException('header_injection', "The {$field} contains invalid control characters.", 422);
        }

        $value = trim($value);
        if ($value === '') {
            throw new OutboundSendException('invalid_'.$field, "The {$field} value is required.", 422);
        }

        if (mb_strlen($value) > $maxLength) {
            throw new OutboundSendException($field.'_too_long', "The {$field} exceeds the maximum length.", 422);
        }

        return $value;
    }

    private function safeLocalDomain(?string $localDomain): string
    {
        $domain = trim((string) ($localDomain ?: config('outbound.smtp.local_domain') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        $domain = strtolower(preg_replace('/[^a-z0-9.-]/i', '', $domain) ?: 'localhost');

        return $domain === '' ? 'localhost' : $domain;
    }

    private function stripControls(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }
}
