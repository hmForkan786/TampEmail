<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;

final class OutboundRecipientValidator
{
    /**
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
     */
    public function validate(mixed $to, mixed $cc = [], mixed $bcc = []): array
    {
        $normalizedTo = $this->normalizeList($to, 'to');
        $normalizedCc = $this->normalizeList($cc ?? [], 'cc');
        $normalizedBcc = $this->normalizeList($bcc ?? [], 'bcc');

        $all = array_values(array_unique([...$normalizedTo, ...$normalizedCc, ...$normalizedBcc]));

        if ($all === []) {
            throw new OutboundSendException('recipients_required', 'At least one recipient is required.', 422);
        }

        $max = (int) config('outbound.max_recipients_per_message', 20);
        if (count($all) > $max) {
            throw new OutboundSendException('recipients_limit', "A message may include at most {$max} recipients.", 422);
        }

        $seen = [];
        $toOut = [];
        $ccOut = [];
        $bccOut = [];

        foreach ($normalizedTo as $address) {
            if (! isset($seen[$address])) {
                $seen[$address] = true;
                $toOut[] = $address;
            }
        }
        foreach ($normalizedCc as $address) {
            if (! isset($seen[$address])) {
                $seen[$address] = true;
                $ccOut[] = $address;
            }
        }
        foreach ($normalizedBcc as $address) {
            if (! isset($seen[$address])) {
                $seen[$address] = true;
                $bccOut[] = $address;
            }
        }

        if ($toOut === []) {
            throw new OutboundSendException('to_required', 'At least one To recipient is required.', 422);
        }

        return ['to' => $toOut, 'cc' => $ccOut, 'bcc' => $bccOut];
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            throw new OutboundSendException('recipients_invalid', "The {$field} field must be an array of email addresses.", 422);
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new OutboundSendException('recipients_invalid', "The {$field} field must contain email strings only.", 422);
            }

            $out[] = $this->normalizeAddress($item, $field);
        }

        return array_values(array_unique($out));
    }

    public function normalizeAddress(string $address, string $field = 'recipient'): string
    {
        $trimmed = trim($address);

        if ($trimmed === '' || preg_match('/[\r\n\0]/', $trimmed) === 1) {
            throw new OutboundSendException('recipient_injection', "The {$field} address contains invalid control characters.", 422);
        }

        $normalized = mb_strtolower($trimmed);

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new OutboundSendException('recipient_invalid', "The {$field} address is invalid.", 422);
        }

        if (mb_strlen($normalized) > 255) {
            throw new OutboundSendException('recipient_invalid', "The {$field} address is too long.", 422);
        }

        return $normalized;
    }
}
