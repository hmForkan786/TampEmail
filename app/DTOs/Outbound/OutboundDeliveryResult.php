<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

use App\Enums\OutboundTransportResult;
use InvalidArgumentException;

/**
 * Safe transport submission result.
 *
 * Never include raw provider responses, credentials, or message bodies.
 */
final readonly class OutboundDeliveryResult
{
    public function __construct(
        public OutboundTransportResult $result,
        public ?string $provider = null,
        public ?string $providerMessageId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {
        if ($this->result === OutboundTransportResult::Accepted && $this->failureCode !== null) {
            throw new InvalidArgumentException('Accepted transport results must not include a failure code.');
        }

        if ($this->result !== OutboundTransportResult::Accepted && ($this->failureCode === null || $this->failureCode === '')) {
            throw new InvalidArgumentException('Non-accepted transport results require a sanitized failure code.');
        }
    }

    public static function accepted(?string $provider = null, ?string $providerMessageId = null): self
    {
        return new self(
            result: OutboundTransportResult::Accepted,
            provider: $provider,
            providerMessageId: $providerMessageId,
        );
    }

    public static function rejected(string $failureCode, ?string $failureMessage = null, ?string $provider = null): self
    {
        return new self(
            result: OutboundTransportResult::Rejected,
            provider: $provider,
            failureCode: self::sanitizeCode($failureCode),
            failureMessage: self::sanitizeMessage($failureMessage),
        );
    }

    public static function temporaryFailure(string $failureCode, ?string $failureMessage = null, ?string $provider = null): self
    {
        return new self(
            result: OutboundTransportResult::TemporaryFailure,
            provider: $provider,
            failureCode: self::sanitizeCode($failureCode),
            failureMessage: self::sanitizeMessage($failureMessage),
        );
    }

    public static function permanentFailure(string $failureCode, ?string $failureMessage = null, ?string $provider = null): self
    {
        return new self(
            result: OutboundTransportResult::PermanentFailure,
            provider: $provider,
            failureCode: self::sanitizeCode($failureCode),
            failureMessage: self::sanitizeMessage($failureMessage),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'result' => $this->result->value,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
        ];
    }

    public static function fromArray(array $payload): self
    {
        $result = OutboundTransportResult::tryFrom((string) ($payload['result'] ?? ''));

        if ($result === null) {
            throw new InvalidArgumentException('Invalid outbound transport result.');
        }

        return new self(
            result: $result,
            provider: isset($payload['provider']) ? (string) $payload['provider'] : null,
            providerMessageId: isset($payload['provider_message_id']) ? (string) $payload['provider_message_id'] : null,
            failureCode: isset($payload['failure_code']) ? self::sanitizeCode((string) $payload['failure_code']) : null,
            failureMessage: isset($payload['failure_message']) ? self::sanitizeMessage((string) $payload['failure_message']) : null,
        );
    }

    private static function sanitizeCode(string $code): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._:-]/', '', $code) ?: 'transport_error';

        return mb_substr($sanitized, 0, 80);
    }

    private static function sanitizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? '';
        $clean = trim($clean);

        return $clean === '' ? null : mb_substr($clean, 0, 255);
    }
}
