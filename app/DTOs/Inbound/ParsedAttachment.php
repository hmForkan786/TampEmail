<?php
declare(strict_types=1);
namespace App\DTOs\Inbound;
final readonly class ParsedAttachment
{
    public function __construct(public string $filename, public string $mimeType, public string $content, public int $sizeBytes, public string $checksumSha256, public bool $inline, public ?string $contentId) {}
}
