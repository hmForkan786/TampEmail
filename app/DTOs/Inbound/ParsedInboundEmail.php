<?php
declare(strict_types=1);
namespace App\DTOs\Inbound;
use Carbon\CarbonInterface;
final readonly class ParsedInboundEmail
{
    public function __construct(public string $messageId, public string $senderEmail, public string $recipientEmail, public ?string $subject, public CarbonInterface $receivedAt, public array $headers, public ?string $textBody, public ?string $htmlBody, public array $attachments, public int $sizeBytes) {}
}
