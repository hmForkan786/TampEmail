<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\DTOs\Inbound\ParsedAttachment;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\DTOs\Inbound\ProviderWebhookEnvelope;
use Carbon\Carbon;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

final class InboundMimeParser
{
    public function __construct(
        private readonly MailMimeParser $parser = new MailMimeParser,
    ) {}

    public function parse(ProviderWebhookEnvelope $envelope): ParsedInboundEmail
    {
        $message = $this->parser->parse($envelope->rawMimePayload, false);

        $headers = [];
        foreach (['message-id', 'from', 'to', 'cc', 'subject', 'date', 'content-type', 'content-transfer-encoding'] as $name) {
            $value = $message->getHeaderValue($name);
            if ($value !== null && $value !== '') {
                $headers[$name] = mb_substr($value, 0, 1000);
            }
        }
        $rawMessageId = $this->extractRawHeaderValue($envelope->rawMimePayload, 'Message-ID');
        if ($rawMessageId !== null && $rawMessageId !== '') {
            $headers['message-id'] = mb_substr($rawMessageId, 0, 1000);
        }

        $mimeMessageId = trim((string) ($message->getMessageId() ?? ($headers['message-id'] ?? '')));
        // Provider webhook idempotency authority: X-Inbound-Message-Id wins over MIME Message-ID.
        $messageId = $envelope->providerMessageId !== '' ? $envelope->providerMessageId : $mimeMessageId;
        if ($messageId === '') {
            throw new \InvalidArgumentException('Inbound message requires a provider or MIME message ID.');
        }
        $storedMimeMessageId = $headers['message-id'] ?? $mimeMessageId;
        if ($storedMimeMessageId !== '' && $storedMimeMessageId !== $messageId) {
            $headers['mime_message_id'] = $storedMimeMessageId;
        }

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $attachments[] = $this->toParsedAttachment($part);
        }

        $from = $this->firstMailbox($message, 'from') ?? $envelope->sender ?? '';
        $to = $this->firstMailbox($message, 'to') ?? $envelope->recipient;
        $receivedAt = $this->parseReceivedAt($message, $envelope);

        return new ParsedInboundEmail(
            $messageId,
            $from,
            $to,
            $message->getSubject(),
            $receivedAt,
            $headers,
            $message->getTextContent(),
            $message->getHtmlContent(),
            $attachments,
            $envelope->contentLength,
        );
    }

    private function firstMailbox(IMessage $message, string $header): ?string
    {
        $value = $message->getHeaderValue($header);
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($value));
    }

    private function toParsedAttachment(IMessagePart $part): ParsedAttachment
    {
        $content = (string) ($part->getContent() ?? '');
        $filename = $part->getFilename() ?? 'attachment';
        $contentId = $part->getContentId();
        $mime = $part->getContentType() ?? 'application/octet-stream';
        $disposition = strtolower((string) ($part->getContentDisposition() ?? ''));
        $inline = $disposition === 'inline' || $contentId !== null;

        return new ParsedAttachment(
            $filename,
            $mime,
            $content,
            strlen($content),
            hash('sha256', $content),
            $inline,
            $contentId,
        );
    }

    private function parseReceivedAt(IMessage $message, ProviderWebhookEnvelope $envelope): Carbon
    {
        $dateHeader = $message->getHeaderValue('date');
        if ($dateHeader === null || $dateHeader === '') {
            return Carbon::instance($envelope->receivedAt);
        }

        try {
            return Carbon::parse($dateHeader);
        } catch (\Throwable) {
            return Carbon::instance($envelope->receivedAt);
        }
    }

    private function extractRawHeaderValue(string $rawMime, string $headerName): ?string
    {
        $pattern = '/^'.preg_quote($headerName, '/').':\s*(.+)$/mi';
        if (preg_match($pattern, $rawMime, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
