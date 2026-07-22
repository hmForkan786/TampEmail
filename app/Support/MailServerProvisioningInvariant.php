<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidMailServerProvisioningDataException;

final class MailServerProvisioningInvariant
{
    public static function poolKey(?string $poolKey): ?string
    {
        if ($poolKey === null) {
            return null;
        }

        $normalized = trim($poolKey);

        if ($normalized === '') {
            throw new InvalidMailServerProvisioningDataException('The pool key must not be blank.');
        }

        if (mb_strlen($normalized) > 255) {
            throw new InvalidMailServerProvisioningDataException('The pool key is too long.');
        }

        return $normalized;
    }

    public static function maxInboxes(?int $maxInboxes): ?int
    {
        if ($maxInboxes === null) {
            return null;
        }

        if ($maxInboxes < 1) {
            throw new InvalidMailServerProvisioningDataException('max_inboxes must be a positive integer or null.');
        }

        return $maxInboxes;
    }
}
