<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Throwable;

/**
 * Validates IANA timezones and converts local wall times to UTC.
 *
 * DST rules:
 * - Gap (spring forward): local time does not exist → reject.
 * - Overlap (fall back): ambiguous local time → earlier occurrence.
 * - Exact-now boundary: scheduled_at must be strictly after "now" UTC.
 */
final class OutboundScheduleTimezone
{
    /**
     * @return array{utc: CarbonImmutable, timezone: string}
     */
    public function resolveFutureLocal(string $localDate, string $localTime, string $timezone, ?CarbonImmutable $now = null): array
    {
        $timezone = trim($timezone);
        if ($timezone === '' || ! $this->isValidIanaTimezone($timezone)) {
            throw new OutboundSendException('schedule_timezone_invalid', 'The schedule timezone is invalid.', 422);
        }

        $localDate = trim($localDate);
        $localTime = trim($localTime);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $localTime)) {
            throw new OutboundSendException('schedule_time_invalid', 'The schedule date or time is invalid.', 422);
        }

        if (strlen($localTime) === 5) {
            $localTime .= ':00';
        }

        $wall = $localDate.' '.$localTime;
        $utc = $this->localWallToUtc($wall, $timezone);
        $now ??= CarbonImmutable::now('UTC');

        if ($utc->lessThanOrEqualTo($now)) {
            throw new OutboundSendException('schedule_time_invalid', 'The schedule time must be in the future.', 422);
        }

        return ['utc' => $utc, 'timezone' => $timezone];
    }

    public function isValidIanaTimezone(string $timezone): bool
    {
        if ($timezone === 'UTC') {
            return true;
        }

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return false;
        }

        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public function localWallToUtc(string $wall, string $timezone): CarbonImmutable
    {
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception) {
            throw new OutboundSendException('schedule_timezone_invalid', 'The schedule timezone is invalid.', 422);
        }

        try {
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wall, $tz);
        } catch (Throwable) {
            throw new OutboundSendException('schedule_time_invalid', 'The schedule date or time is invalid.', 422);
        }

        if (! $local instanceof CarbonImmutable || $local->format('Y-m-d H:i:s') !== $wall) {
            throw new OutboundSendException('schedule_time_invalid', 'The schedule local time does not exist (DST gap).', 422);
        }

        $candidates = $this->utcCandidatesForWall($wall, $tz);
        if ($candidates === []) {
            throw new OutboundSendException('schedule_time_invalid', 'The schedule date or time is invalid.', 422);
        }

        usort($candidates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $candidates[0];
    }

    public function formatLocal(CarbonImmutable $utc, string $timezone): string
    {
        return $utc->setTimezone($timezone)->format('Y-m-d H:i:s');
    }

    /**
     * Common IANA zones for schedule UI selects (UTC first).
     *
     * @return list<string>
     */
    public function commonTimezones(): array
    {
        return [
            'UTC',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Toronto',
            'America/Sao_Paulo',
            'Europe/London',
            'Europe/Paris',
            'Europe/Berlin',
            'Asia/Dubai',
            'Asia/Kolkata',
            'Asia/Singapore',
            'Asia/Tokyo',
            'Australia/Sydney',
            'Pacific/Auckland',
        ];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function utcCandidatesForWall(string $wall, DateTimeZone $tz): array
    {
        $direct = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wall, $tz);
        if (! $direct instanceof CarbonImmutable || $direct->format('Y-m-d H:i:s') !== $wall) {
            return [];
        }

        $offsets = [(int) $direct->getOffset()];
        [$date] = explode(' ', $wall, 2);
        $dayStart = CarbonImmutable::parse($date.' 00:00:00', $tz);
        $dayEnd = $dayStart->addDay();

        foreach ($tz->getTransitions($dayStart->getTimestamp(), $dayEnd->getTimestamp()) as $transition) {
            $offsets[] = (int) $transition['offset'];
        }

        $offsets = array_values(array_unique($offsets));
        $candidates = [];

        foreach ($offsets as $offsetSeconds) {
            $asUtc = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wall, new DateTimeZone('UTC'));
            if (! $asUtc instanceof CarbonImmutable) {
                continue;
            }

            $mapped = $asUtc->subSeconds($offsetSeconds);
            if ($mapped->setTimezone($tz)->format('Y-m-d H:i:s') === $wall) {
                $candidates[(string) $mapped->getTimestamp()] = $mapped;
            }
        }

        $candidates[(string) $direct->utc()->getTimestamp()] = $direct->utc();

        return array_values($candidates);
    }
}
