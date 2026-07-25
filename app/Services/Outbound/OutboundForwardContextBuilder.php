<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Models\Email;
use App\Services\Inbound\InboundHtmlSanitizer;

final class OutboundForwardContextBuilder
{
    public function __construct(
        private readonly InboundHtmlSanitizer $htmlSanitizer,
    ) {}

    public function buildText(?string $introduction, Email $email): string
    {
        $email->loadMissing('body');

        $lines = [
            '---------- Forwarded message ----------',
            'From: '.$email->sender_email,
            'Date: '.($email->received_at?->toRfc7231String() ?? ''),
            'Subject: '.(string) $email->subject,
            'To: '.$email->recipient_email,
            '',
        ];

        $body = $email->body?->text_body;
        if ($body !== null && trim($body) !== '') {
            $lines[] = mb_substr($body, 0, 20000);
        }

        $context = implode("\n", $lines);
        if ($introduction === null || trim($introduction) === '') {
            return $context;
        }

        return rtrim($introduction)."\n\n".$context;
    }

    public function buildHtml(?string $introductionHtml, Email $email): ?string
    {
        $email->loadMissing('body');
        $originalHtml = $this->htmlSanitizer->sanitize($email->body?->html_body);
        $intro = $introductionHtml !== null ? $this->htmlSanitizer->sanitize($introductionHtml) : null;

        $meta = '<p><strong>---------- Forwarded message ----------</strong><br>'
            .'From: '.e($email->sender_email).'<br>'
            .'Date: '.e((string) $email->received_at?->toRfc7231String()).'<br>'
            .'Subject: '.e((string) $email->subject).'<br>'
            .'To: '.e($email->recipient_email).'</p>';

        $parts = array_filter([
            $intro,
            $meta,
            $originalHtml,
        ], fn (?string $part): bool => $part !== null && trim($part) !== '');

        if ($parts === []) {
            return null;
        }

        return implode("\n", $parts);
    }
}
