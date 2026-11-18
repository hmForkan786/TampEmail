<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

final class InvoiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        /** @var array<string, mixed> */
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $details */
    public static function ledgerMismatch(string $message, array $details = []): self
    {
        return new self('invoice_ledger_mismatch', $message, 422, $details);
    }

    public static function invalidTransition(string $message): self
    {
        return new self('invoice_invalid_transition', $message, 422);
    }

    public static function notFound(): self
    {
        return new self('invoice_not_found', 'Invoice not found.', 404);
    }

    public static function unauthorized(): self
    {
        return new self('invoice_forbidden', 'Invoice access denied.', 403);
    }
}
