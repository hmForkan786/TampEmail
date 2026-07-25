<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;

final class OutboundSubjectHelper
{
    public function replySubject(?string $original, ?string $override = null): string
    {
        $base = $override !== null && trim($override) !== '' ? trim($override) : trim((string) $original);
        $base = $this->sanitize($base);

        if ($base === '') {
            $base = 'Re:';
        } elseif (! preg_match('/^re:\s*/i', $base)) {
            $base = 'Re: '.$base;
        } else {
            $base = preg_replace('/^(re:\s*)+/i', 'Re: ', $base) ?? ('Re: '.$base);
        }

        return $this->enforceMax($base);
    }

    public function forwardSubject(?string $original, ?string $override = null): string
    {
        $base = $override !== null && trim($override) !== '' ? trim($override) : trim((string) $original);
        $base = $this->sanitize($base);

        if ($base === '') {
            $base = 'Fwd:';
        } elseif (preg_match('/^(fwd|fw):\s*/i', $base) === 1) {
            $base = preg_replace('/^(fwd|fw):\s*/i', 'Fwd: ', $base) ?? ('Fwd: '.$base);
        } else {
            $base = 'Fwd: '.$base;
        }

        return $this->enforceMax($base);
    }

    private function sanitize(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new OutboundSendException('header_injection', 'The subject contains invalid control characters.', 422);
        }

        return trim($value);
    }

    private function enforceMax(string $value): string
    {
        $max = (int) config('outbound.max_subject_length', 998);
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max);
        }

        return $value;
    }
}
