<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use App\DTOs\Billing\CanonicalWebhookPayload;
use App\DTOs\Billing\RawWebhookRequest;
use App\Enums\WebhookCanonicalizationStrategy;
use InvalidArgumentException;

final class WebhookPayloadCanonicalizer
{
    public function canonicalize(RawWebhookRequest $request, WebhookCanonicalizationStrategy $strategy, ?string $timestamp = null, ?string $nonce = null): CanonicalWebhookPayload
    {
        $bytes = match ($strategy) {
            WebhookCanonicalizationStrategy::RawBody => $request->rawBody,
            WebhookCanonicalizationStrategy::TimestampDotRawBody => $this->required($timestamp).'.'.$request->rawBody,
            WebhookCanonicalizationStrategy::TimestampNonceRawBody => $this->required($timestamp).'.'.$this->required($nonce).'.'.$request->rawBody,
            WebhookCanonicalizationStrategy::TimestampNewlineRawBody => $this->required($timestamp)."\n".$request->rawBody,
            WebhookCanonicalizationStrategy::MethodPathQueryBody => $request->method."\n".$request->path."\n".$request->queryString."\n".$request->rawBody,
            WebhookCanonicalizationStrategy::SortedFormFields => $this->sortedForm($request->rawBody),
            WebhookCanonicalizationStrategy::ProviderDefined => throw new InvalidArgumentException('Provider-defined canonicalization requires an adapter implementation.'),
        };
        $limit = app()->bound('config') ? (int) config('billing.webhook_security.max_canonical_payload_bytes', 524288) : 524288;
        if (strlen($bytes) > $limit) {
            throw new InvalidArgumentException('Canonical payload exceeds configured limit.');
        }

        return new CanonicalWebhookPayload($bytes, $strategy, hash('sha256', $bytes));
    }

    private function required(?string $value): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('Required canonicalization component is absent.');
        }

        return $value;
    }

    private function sortedForm(string $body): string
    {
        parse_str($body, $fields);
        ksort($fields, SORT_STRING);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
