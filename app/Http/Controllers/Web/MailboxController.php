<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Commercial\CommercialUsageSummaryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Owner-scoped, metadata-only inbox landing page for the session UI. */
final class MailboxController extends Controller
{
    public function __construct(
        private readonly CommercialUsageSummaryService $commercialUsage,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $inboxes = Inbox::query()
            ->ownedBy((string) $user->getKey())
            ->visibleToOwner()
            ->orderByDesc('last_received_at')
            ->get();

        $requested = $request->query('inbox');
        $selected = is_string($requested) ? $inboxes->firstWhere('id', $requested) : $inboxes->first();
        $emails = $selected instanceof Inbox
            ? Email::query()->where('inbox_id', $selected->getKey())->latest('received_at')->paginate(25)
            : null;
        $emailId = $request->query('email');
        $selectedEmail = $selected instanceof Inbox && is_string($emailId)
            ? Email::query()->where('inbox_id', $selected->getKey())->with('body')->find($emailId)
            : null;
        $commercialSummary = $this->commercialUsage->forUser($user, evaluateThresholds: false);

        return view('mailbox.index', compact('inboxes', 'selected', 'emails', 'selectedEmail', 'commercialSummary'));
    }
}
