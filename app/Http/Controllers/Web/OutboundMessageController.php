<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Outbound\CancelOutboundMessageAction;
use App\Actions\Outbound\DeleteOutboundMessageAction;
use App\Actions\Outbound\RetryOutboundMessageAction;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Exceptions\OutboundSendException;
use App\Http\Controllers\Controller;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;
use App\Services\Inbound\InboundHtmlSanitizer;
use App\Services\Outbound\OutboundFailureCategoryMapper;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundMessageAccessService;
use App\Services\Outbound\OutboundMessageListingService;
use App\Services\Outbound\OutboundMessageTimelineBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User-facing outbound message list/detail pages.
 *
 * Thin controller: all query scoping, eligibility, and safe-attachment
 * filtering live in {@see OutboundMessageListingService} and
 * {@see OutboundMessageAccessService}; cancel/retry mutations delegate to
 * the same actions the API uses so there is exactly one rule set.
 */
final class OutboundMessageController extends Controller
{
    public function __construct(
        private readonly OutboundMessageListingService $listingService,
        private readonly OutboundMessageAccessService $accessService,
        private readonly OutboundMessageTimelineBuilder $timelineBuilder,
        private readonly CancelOutboundMessageAction $cancelAction,
        private readonly DeleteOutboundMessageAction $deleteAction,
        private readonly RetryOutboundMessageAction $retryAction,
        private readonly OutboundLaunchControlService $launchControl,
        private readonly EntitlementService $entitlements,
        private readonly OutboundFailureCategoryMapper $categories,
        private readonly InboundHtmlSanitizer $htmlSanitizer,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $messages = $this->listingService->list($user, $request->query());
        $messages->setCollection(
            $messages->getCollection()->map(fn (OutboundMessage $message): array => $this->rowViewModel($message)),
        );

        return view('outbound-messages.index', [
            'messages' => $messages,
            'filters' => $request->query(),
            'states' => OutboundMessageState::labels(),
            'operations' => OutboundOperation::labels(),
            'banner' => $this->degradedBanner($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowViewModel(OutboundMessage $message): array
    {
        $recipients = $message->to_recipients ?? [];

        return [
            'id' => $message->id,
            'subject' => $message->subject,
            'primary_recipient' => $recipients[0] ?? null,
            'additional_recipients' => max(0, count($recipients) - 1),
            'operation_label' => $message->operation?->label(),
            'inbox_address' => $message->inbox?->full_address,
            'state' => $message->state?->value,
            'state_label' => $message->state?->label(),
            'created_at' => $message->created_at,
            'sent_at' => $message->sent_at,
            'delivered_at' => $message->delivered_at,
            'attachment_count' => count($message->attachment_ids ?? []),
            'failure_category' => $message->failure_code !== null
                ? $this->categories->userSafeCategory($message->failure_code)
                : null,
        ];
    }

    public function show(Request $request, string $message): View
    {
        /** @var User $user */
        $user = $request->user();

        $outbound = $this->accessService->findOwned($user, $message);

        abort_if($outbound === null, 404);

        $outbound->setRelation('safeAttachments', $this->accessService->listSafeAttachments($outbound));

        return view('outbound-messages.show', [
            'message' => $outbound,
            'sanitizedHtmlBody' => $this->safeHtmlForDisplay($outbound->html_body),
            'timeline' => $this->timelineBuilder->build($outbound, admin: false),
            'attemptSummary' => $this->accessService->attemptSummary($outbound),
            'canCancel' => $this->accessService->canCancel($outbound),
            'canRetry' => $this->accessService->canRetry($outbound),
            'canDelete' => $this->accessService->canDelete($outbound),
            'attachments' => $outbound->safeAttachments,
            'failureCategory' => $outbound->failure_code !== null
                ? $this->categories->userSafeCategory($outbound->failure_code)
                : null,
            'banner' => $this->degradedBanner($user),
        ]);
    }

    /**
     * Sanitizes the stored HTML body and additionally strips remote
     * `<img>` sources in the user-facing view (defense in depth on top of
     * the sanitizer, which already removes scripts and unsafe elements).
     */
    private function safeHtmlForDisplay(?string $html): ?string
    {
        $sanitized = $this->htmlSanitizer->sanitize($html);

        if ($sanitized === null) {
            return null;
        }

        return preg_replace('/(<img\b[^>]*\s)src=(["\'])https?:\/\/[^"\']*\2/i', '$1data-remote-image-blocked=$2$2', $sanitized) ?? $sanitized;
    }

    public function cancel(Request $request, string $message): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $outbound = $this->accessService->findOwned($user, $message);

        abort_if($outbound === null, 404);

        try {
            $this->cancelAction->execute($outbound->getKey(), $user);
        } catch (OutboundSendException $exception) {
            return back()->with('outboundError', $exception->getMessage());
        }

        return redirect()
            ->route('outbound-messages.show', $outbound)
            ->with('outboundStatus', 'Message cancelled.');
    }

    public function retry(Request $request, string $message): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $outbound = $this->accessService->findOwned($user, $message);

        abort_if($outbound === null, 404);

        try {
            $this->retryAction->execute($outbound->getKey(), $user);
        } catch (OutboundSendException $exception) {
            return back()->with('outboundError', $exception->getMessage());
        }

        return redirect()
            ->route('outbound-messages.show', $outbound)
            ->with('outboundStatus', 'Retry requested.');
    }

    public function destroy(Request $request, string $message): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $outbound = $this->accessService->findOwned($user, $message);

        abort_if($outbound === null, 404);

        try {
            $this->deleteAction->execute($outbound->getKey(), $user);
        } catch (OutboundSendException $exception) {
            return back()->with('outboundError', $exception->getMessage());
        }

        return redirect()
            ->route('outbound-messages.index')
            ->with('outboundStatus', 'Message deleted.');
    }

    /**
     * Read-only degraded-state banner. Never claims delivery beyond what
     * the message state itself supports (e.g. never says "delivered" for
     * a message that is merely "sent").
     */
    private function degradedBanner(User $user): ?string
    {
        if ($this->launchControl->isEmergencyStopped()) {
            return 'Outbound email is temporarily paused. New sends and retries are not available right now.';
        }

        if (! config('outbound.enabled', false)) {
            return 'Outbound email is currently disabled.';
        }

        if (! $this->entitlements->hasFeature($user, OutboundOperation::Send->featureKey())) {
            return 'Your current plan does not include outbound email.';
        }

        return null;
    }
}
