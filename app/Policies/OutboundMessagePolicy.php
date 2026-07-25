<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundMessageAccessService;

/**
 * Ownership and mutation-eligibility policy for outbound messages.
 *
 * Documents the access rules for clarity; controllers must still scope
 * their queries by `user_id` themselves (this policy never substitutes
 * for that scoping, it only expresses the same rule as an authorization
 * check).
 */
final class OutboundMessagePolicy
{
    public function __construct(
        private readonly OutboundMessageAccessService $access,
    ) {}

    public function view(User $user, OutboundMessage $message): bool
    {
        return (string) $message->user_id === (string) $user->getKey();
    }

    public function cancel(User $user, OutboundMessage $message): bool
    {
        return $this->view($user, $message) && $this->access->canCancel($message);
    }

    public function retry(User $user, OutboundMessage $message): bool
    {
        return $this->view($user, $message) && $this->access->canRetry($message);
    }

    public function delete(User $user, OutboundMessage $message): bool
    {
        return $this->view($user, $message) && $this->access->canDelete($message);
    }
}
