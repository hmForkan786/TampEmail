<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;
use App\Services\Inbound\InboundHtmlSanitizer;

final class OutboundContentValidator
{
    public function __construct(
        private readonly InboundHtmlSanitizer $htmlSanitizer,
    ) {}

    /**
     * @return array{subject: string, text_body: string|null, html_body: string|null, from_display_name: string|null}
     */
    public function validate(
        ?string $subject,
        ?string $textBody,
        ?string $htmlBody,
        ?string $fromDisplayName = null,
    ): array {
        $subject = $this->sanitizeHeaderValue($subject ?? '', 'subject');
        $maxSubject = (int) config('outbound.max_subject_length', 998);
        if (mb_strlen($subject) > $maxSubject) {
            throw new OutboundSendException('subject_too_long', "Subject may be at most {$maxSubject} characters.", 422);
        }

        $text = $textBody !== null ? $this->stripControls($textBody) : null;
        $html = $htmlBody !== null ? $this->htmlSanitizer->sanitize($htmlBody) : null;

        if ($text !== null && strlen($text) > (int) config('outbound.max_text_body_bytes', 102400)) {
            throw new OutboundSendException('text_body_too_large', 'The text body exceeds the maximum size.', 422);
        }

        if ($html !== null && strlen($html) > (int) config('outbound.max_html_body_bytes', 204800)) {
            throw new OutboundSendException('html_body_too_large', 'The HTML body exceeds the maximum size.', 422);
        }

        $textEmpty = $text === null || trim($text) === '';
        $htmlEmpty = $html === null || trim($html) === '';
        if ($textEmpty && $htmlEmpty) {
            throw new OutboundSendException('body_required', 'A text or HTML body is required.', 422);
        }

        $display = null;
        if ($fromDisplayName !== null && trim($fromDisplayName) !== '') {
            $display = $this->sanitizeHeaderValue($fromDisplayName, 'from_display_name');
            if (mb_strlen($display) > 255) {
                throw new OutboundSendException('display_name_too_long', 'The display name is too long.', 422);
            }
        }

        return [
            'subject' => $subject,
            'text_body' => $textEmpty ? null : $text,
            'html_body' => $htmlEmpty ? null : $html,
            'from_display_name' => $display,
        ];
    }

    private function sanitizeHeaderValue(string $value, string $field): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new OutboundSendException('header_injection', "The {$field} contains invalid control characters.", 422);
        }

        return trim($value);
    }

    private function stripControls(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }
}
