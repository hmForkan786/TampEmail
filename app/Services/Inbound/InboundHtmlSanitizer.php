<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
final class InboundHtmlSanitizer
{
    private readonly HtmlSanitizer $sanitizer;
    public function __construct() { $this->sanitizer = new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()); }
    public function sanitize(?string $html): ?string { return $html === null ? null : $this->sanitizer->sanitize($html); }
}
