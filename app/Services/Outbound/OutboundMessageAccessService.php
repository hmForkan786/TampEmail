<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Actions\Outbound\DeleteOutboundMessageAction;
use App\Actions\Outbound\RetryOutboundMessageAction;
use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\Attachment;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only owner access, safe-attachment listing, and UI-affordance
 * checks for a single outbound message.
 *
 * `canRetry()` mirrors the eligibility rules already enforced by
 * {@see RetryOutboundMessageAction} (terminal state,
 * non-retryable failure category, current send authorization) without
 * duplicating them: it re-uses the same non-retryable code list and calls
 * {@see OutboundAuthorizationService::assertCanSend()} read-only. It never
 * performs the mutation itself.
 */
final class OutboundMessageAccessService
{
    /**
     * Mirrors RetryOutboundMessageAction::execute()'s non-retryable list.
     *
     * @var list<string>
     */
    private const NON_RETRYABLE_FAILURE_CODES = [
        'attachment_unsafe',
        'attachment_not_found',
        'attachment_deleted',
        'attachment_unavailable',
        'user_inactive',
        'inbox_inactive',
        'domain_outbound_disabled',
        'entitlement_denied',
        'invalid_config',
    ];

    public function __construct(
        private readonly OutboundAuthorizationService $authorization,
        private readonly AttachmentVisibilityPolicy $attachmentVisibility,
        private readonly OutboundFailureCategoryMapper $categories,
    ) {}

    /**
     * Normal owner-facing lookup: excludes messages the owner has hidden
     * via {@see DeleteOutboundMessageAction}. Never
     * used by admin/ops views, which query {@see OutboundMessage} directly
     * and are unaffected by user deletion.
     */
    public function findOwned(User $owner, string $id): ?OutboundMessage
    {
        return OutboundMessage::query()
            ->whereKey($id)
            ->where('user_id', $owner->getKey())
            ->whereNull('user_deleted_at')
            ->with(['inbox.domain'])
            ->first();
    }

    /**
     * @return Collection<int, Attachment>
     */
    public function listSafeAttachments(OutboundMessage $message): Collection
    {
        $ids = $message->attachment_ids ?? [];

        if ($ids === [] || $message->source_email_id === null) {
            return new Collection;
        }

        return Attachment::query()
            ->where('email_id', $message->source_email_id)
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Attachment $attachment): bool => $this->attachmentVisibility->view(null, $attachment))
            ->values();
    }

    public function canCancel(OutboundMessage $message): bool
    {
        return $message->state === OutboundMessageState::Queued;
    }

    /**
     * The only precondition for the owner's hide/delete affordance is that
     * the message has not already been hidden; every other state (queued,
     * sending, sent, delivered, failed, cancelled) may be hidden.
     */
    public function canDelete(OutboundMessage $message): bool
    {
        return ! $message->isUserDeleted();
    }

    public function canRetry(OutboundMessage $message): bool
    {
        if ($message->state !== OutboundMessageState::Failed) {
            return false;
        }

        if (in_array((string) $message->failure_code, self::NON_RETRYABLE_FAILURE_CODES, true)) {
            return false;
        }

        $message->loadMissing(['inbox.domain', 'user']);

        if ($message->inbox === null || $message->user === null) {
            return false;
        }

        try {
            $this->authorization->assertCanSend($message->user, $message->inbox, $message->operation);

            return true;
        } catch (OutboundSendException) {
            return false;
        }
    }

    /**
     * Safe delivery-attempt summary: only a count and the last attempt's
     * state/user-safe category — never the raw `result` text or provider
     * identity that individual attempt rows may carry.
     *
     * @return array{count: int, last_state: ?string, last_category: ?string}
     */
    public function attemptSummary(OutboundMessage $message): array
    {
        $attempts = $message->relationLoaded('deliveryAttempts')
            ? $message->deliveryAttempts
            : $message->deliveryAttempts()->get();

        $last = $attempts->last();

        return [
            'count' => $attempts->count(),
            'last_state' => $last?->state?->value,
            'last_category' => $last?->failure_category !== null
                ? $this->categories->userSafeFromCategory($last->failure_category)
                : null,
        ];
    }
}
