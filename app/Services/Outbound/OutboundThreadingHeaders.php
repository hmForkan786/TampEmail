<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Models\Email;

final class OutboundThreadingHeaders
{
    /**
     * @return array{in_reply_to: string, references: string}
     */
    public function forReply(Email $email): array
    {
        $messageId = $this->normalizeMessageId((string) $email->message_id);
        $existingRefs = $this->headerValue($email, ['references', 'References']);

        $references = trim(($existingRefs !== null ? $existingRefs.' ' : '').$messageId);
        $references = mb_substr(preg_replace('/\s+/', ' ', $references) ?? $references, 0, 4000);

        return [
            'in_reply_to' => $messageId,
            'references' => $references,
        ];
    }

    private function normalizeMessageId(string $messageId): string
    {
        $messageId = trim($messageId);
        $messageId = preg_replace('/[\r\n\0]/', '', $messageId) ?? '';

        if ($messageId === '') {
            return '<unknown@localhost>';
        }

        if (! str_starts_with($messageId, '<')) {
            $messageId = '<'.$messageId;
        }
        if (! str_ends_with($messageId, '>')) {
            $messageId .= '>';
        }

        return mb_substr($messageId, 0, 998);
    }

    /**
     * @param  list<string>  $keys
     */
    private function headerValue(Email $email, array $keys): ?string
    {
        $headers = is_array($email->headers) ? $email->headers : [];
        foreach ($keys as $key) {
            if (! isset($headers[$key])) {
                continue;
            }
            $value = $headers[$key];
            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            }
            if (is_string($value) && trim($value) !== '') {
                return trim(preg_replace('/[\r\n\0]/', ' ', $value) ?? $value);
            }
        }

        return null;
    }
}
