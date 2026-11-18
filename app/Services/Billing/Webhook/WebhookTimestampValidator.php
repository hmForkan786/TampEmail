<?php

declare(strict_types=1);

namespace App\Services\Billing\Webhook;

use DateTimeImmutable;

final class WebhookTimestampValidator
{
    /** @return array{valid:bool, code:string, timestamp:?DateTimeImmutable} */
    public function validate(?string $value, int $replayWindow, int $futureSkew, ?DateTimeImmutable $now = null): array
    {
        if ($value === null || $value === '') {
            return ['valid' => false, 'code' => 'missing', 'timestamp' => null];
        }
        $now ??= new DateTimeImmutable('now');
        try {
            if (preg_match('/^\d{13}$/', $value)) {
                $signedAt = (new DateTimeImmutable('@'.intdiv((int) $value, 1000)));
            } elseif (preg_match('/^\d{10}$/', $value)) {
                $signedAt = new DateTimeImmutable('@'.$value);
            } else {
                $signedAt = new DateTimeImmutable($value);
            }
        } catch (\Throwable) {
            return ['valid' => false, 'code' => 'malformed', 'timestamp' => null];
        }
        $delta = $signedAt->getTimestamp() - $now->getTimestamp();
        if ($delta > $futureSkew) {
            return ['valid' => false, 'code' => 'too_far_in_future', 'timestamp' => $signedAt];
        }
        if ($delta < -$replayWindow) {
            return ['valid' => false, 'code' => 'too_old', 'timestamp' => $signedAt];
        }

        return ['valid' => true, 'code' => 'valid', 'timestamp' => $signedAt];
    }
}
