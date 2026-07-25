<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a verified SNS SubscriptionConfirmation must be handled by an admin.
 */
final class OutboundSnsSubscriptionConfirmationException extends RuntimeException
{
    public function __construct(
        public readonly string $subscribeUrl,
        public readonly string $topicArn,
        public readonly string $token,
        public readonly string $messageId,
    ) {
        parent::__construct('sns_subscription_confirmation');
    }
}
