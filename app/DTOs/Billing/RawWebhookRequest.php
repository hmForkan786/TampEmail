<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use JsonSerializable;

final readonly class RawWebhookRequest implements JsonSerializable
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public string $provider,
        public string $method,
        public string $path,
        public string $queryString,
        public string $rawBody,
        public array $headers,
        public string $contentType,
        public ?int $contentLength,
        public ?string $sourceIp,
        public DateTimeImmutable $receivedAt,
        public string $requestId,
    ) {}

    public static function capture(Request $request, string $provider): self
    {
        return new self(
            strtolower(trim($provider)),
            strtoupper($request->method()),
            '/'.$request->path(),
            (string) $request->server('QUERY_STRING', ''),
            $request->getContent(),
            array_change_key_case($request->headers->all(), CASE_LOWER),
            strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])),
            $request->headers->has('Content-Length') ? (int) $request->header('Content-Length') : null,
            $request->ip(),
            new DateTimeImmutable('now'),
            (string) Str::uuid(),
        );
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return count($values) === 1 ? $values[0] : null;
    }

    /** @return array{provider:string, request_id:string, payload_hash:string} */
    public function jsonSerialize(): array
    {
        return ['provider' => $this->provider, 'request_id' => $this->requestId, 'payload_hash' => hash('sha256', $this->rawBody)];
    }
}
