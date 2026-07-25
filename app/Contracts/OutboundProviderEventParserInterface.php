<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Outbound\OutboundProviderEventData;
use Illuminate\Http\Request;

interface OutboundProviderEventParserInterface
{
    public function supports(string $provider): bool;

    /**
     * Verify the request signature using provider-specific rules.
     * Fail closed when the secret is missing.
     */
    public function verifySignature(Request $request, string $provider, string $rawBody): bool;

    /**
     * Parse a verified request into a sanitized provider event.
     */
    public function parse(Request $request, string $provider, string $rawBody): OutboundProviderEventData;

    /**
     * Stable fingerprint for webhook replay protection (no secrets).
     */
    public function replayFingerprint(Request $request, string $provider, string $rawBody): string;
}
