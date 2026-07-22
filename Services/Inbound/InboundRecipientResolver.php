<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\DTOs\Inbound\InboundResolution;
use App\DTOs\Inbound\RecipientAddress;
use App\DTOs\Inbound\RecipientInput;
use App\Enums\InboundRoutingCode;
use App\Repositories\Contracts\DomainRepositoryInterface;
use App\Repositories\Contracts\InboxRepositoryInterface;
use Illuminate\Support\Str;

final class InboundRecipientResolver
{
    public function __construct(private readonly DomainRepositoryInterface $domains, private readonly InboxRepositoryInterface $inboxes) {}

    public function resolve(RecipientInput|string $input): InboundResolution
    {
        $input = is_string($input) ? new RecipientInput($input) : $input;
        $address = $this->normalize($input->rawRecipient);
        if ($address === null) return new InboundResolution(InboundRoutingCode::InvalidAddress);

        $domain = $this->domains->findByDomain($address->domain);
        if ($domain === null) return new InboundResolution(InboundRoutingCode::UnknownDomain, $address->fullAddress());
        if (! $domain->is_active || $domain->trashed() || ! $domain->is_healthy) {
            return new InboundResolution(InboundRoutingCode::InactiveDomain, $address->fullAddress(), (string) $domain->id, retryable: ! $domain->is_healthy);
        }

        $inbox = $this->inboxes->findByAddress($address->fullAddress());
        if ($inbox === null) return new InboundResolution(InboundRoutingCode::UnknownInbox, $address->fullAddress(), (string) $domain->id);
        if ($inbox->trashed()) return new InboundResolution(InboundRoutingCode::UnknownInbox, $address->fullAddress(), (string) $domain->id);
        if ($inbox->isExpired()) return new InboundResolution(InboundRoutingCode::Expired, $address->fullAddress(), (string) $domain->id, (string) $inbox->id, $inbox->user_id, $inbox->user_id === null);
        if (! $inbox->is_active) return new InboundResolution(InboundRoutingCode::InactiveInbox, $address->fullAddress(), (string) $domain->id, (string) $inbox->id, $inbox->user_id, $inbox->user_id === null);
        if ($input->publicIngress && $inbox->user_id === null && ! $domain->is_public) return new InboundResolution(InboundRoutingCode::PublicIngressDisabled, $address->fullAddress(), (string) $domain->id, (string) $inbox->id, null, true);

        return InboundResolution::fromModels($inbox, $domain);
    }

    private function normalize(string $raw): ?RecipientAddress
    {
        if ($raw === '' || trim($raw) !== $raw || preg_match('/[\x00-\x1F\x7F\r\n]/', $raw) || substr_count($raw, '@') !== 1 || strlen($raw) > 255) return null;
        [$local, $domain] = explode('@', $raw, 2);
        if ($local === '' || $domain === '' || strlen($local) > 120 || strlen($domain) > 255 || preg_match('/\s/', $local.$domain)) return null;
        $domain = strtolower($domain);
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) return null;
            $domain = strtolower($ascii);
        }
        if (! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i', $domain) || str_contains($domain, '..')) return null;
        return new RecipientAddress($local, $domain);
    }
}
