<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\DTOs\Inbound\ProviderWebhookEnvelope;
use App\DTOs\Inbound\ParsedAttachment;
use Symfony\Component\Mime\Email;
final class InboundMimeParser
{
    public function parse(ProviderWebhookEnvelope $envelope): ParsedInboundEmail
    {
        $message = Email::fromString($envelope->rawMimePayload); $headers = [];
        foreach (['message-id','from','to','cc','subject','date','content-type','content-transfer-encoding'] as $name) if ($message->getHeaders()->has($name)) $headers[$name] = mb_substr($message->getHeaders()->get($name)->getBodyAsString(), 0, 1000);
        $attachments = [];
        foreach ($message->getAttachments() as $part) {
            $content = $part->getBody();
            if (is_resource($content)) $content = stream_get_contents($content);
            $content = is_string($content) ? $content : '';
            $filename = (string) ($part->getFilename() ?? 'attachment');
            $contentId = $part->getContentId();
            $attachments[] = new ParsedAttachment($filename, (string) $part->getMediaType().'/'.$part->getMediaSubtype(), $content, strlen($content), hash('sha256', $content), $contentId !== null, $contentId);
        }
        return new ParsedInboundEmail(trim((string)($headers['message-id'] ?? $envelope->providerMessageId)), $message->getFrom()[0]->getAddress() ?? $envelope->sender ?? '', $message->getTo()[0]->getAddress() ?? $envelope->recipient, $message->getSubject(), $message->getDate() ?? $envelope->receivedAt, $headers, $message->getTextBody(), $message->getHtmlBody(), $attachments, $envelope->contentLength);
    }
}
