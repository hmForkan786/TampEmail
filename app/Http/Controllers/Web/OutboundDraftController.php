<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Outbound\ScheduleOutboundDraftAction;
use App\Exceptions\OutboundSendException;
use App\Http\Controllers\Controller;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundScheduleTimezone;
use App\Services\Outbound\OutboundSenderProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OutboundDraftController extends Controller
{
    public function __construct(
        private readonly OutboundDraftService $drafts,
        private readonly ScheduleOutboundDraftAction $scheduleOutboundDraft,
        private readonly OutboundScheduleTimezone $timezones,
    ) {}

    public function index(Request $r): View
    {
        $d = OutboundMessage::query()->with('inbox')->where('user_id', $r->user()->id)->where('state', 'draft')->whereNull('draft_deleted_at')->latest()->paginate();

        return view('outbound-drafts.index', ['drafts' => $d]);
    }

    public function compose(Request $r): View
    {
        return view('outbound-drafts.form', ['draft' => null, 'inboxes' => Inbox::query()->where('user_id', $r->user()->id)->where('is_active', true)->get(), 'profiles' => app(OutboundSenderProfileService::class)->list($r->user()), 'operation' => $r->query('operation', 'send'), 'timezones' => $this->timezones->commonTimezones()]);
    }

    public function edit(Request $r, string $draft): View
    {
        $d = $this->owned($r, $draft);
        abort_if(! $d, 404);

        return view('outbound-drafts.form', ['draft' => $d, 'inboxes' => Inbox::query()->where('user_id', $r->user()->id)->where('is_active', true)->get(), 'profiles' => app(OutboundSenderProfileService::class)->list($r->user()), 'operation' => $d->operation->value, 'timezones' => $this->timezones->commonTimezones()]);
    }

    public function store(Request $r): RedirectResponse
    {
        try {
            $d = $this->drafts->create($r->user(), $this->input($r));

            return redirect()->route('outbound-drafts.edit', $d)->with('outboundStatus', 'Draft saved.');
        } catch (OutboundSendException $e) {
            return back()->withInput()->with('outboundError', $e->getMessage());
        }
    }

    public function update(Request $r, string $draft): RedirectResponse
    {
        try {
            $this->drafts->update($r->user(), $draft, $this->input($r) + ['version' => (int) $r->input('version')]);

            return back()->with('outboundStatus', 'Draft saved.');
        } catch (OutboundSendException $e) {
            return back()->withInput()->with('outboundError', $e->getMessage());
        }
    }

    public function destroy(Request $r, string $draft): RedirectResponse
    {
        try {
            $this->drafts->delete($r->user(), $draft, (int) $r->input('version'));
        } catch (OutboundSendException $e) {
            return back()->with('outboundError', $e->getMessage());
        }

        return redirect()->route('outbound-drafts.index')->with('outboundStatus', 'Draft deleted.');
    }

    public function schedule(Request $r, string $draft): RedirectResponse
    {
        try {
            $message = $this->scheduleOutboundDraft->execute(
                $r->user(),
                $draft,
                (int) $r->input('version'),
                (string) $r->input('local_date'),
                (string) $r->input('local_time'),
                (string) $r->input('timezone'),
            );

            return redirect()->route('outbound-messages.show', $message)->with('outboundStatus', 'Message scheduled.');
        } catch (OutboundSendException $e) {
            return back()->withInput()->with('outboundError', $e->getMessage());
        }
    }

    public function submit(Request $r, string $draft): RedirectResponse
    {
        try {
            $m = $this->drafts->submit($r->user(), $draft, (int) $r->input('version'));

            return redirect()->route('outbound-messages.show', $m)->with('outboundStatus', 'Message queued.');
        } catch (OutboundSendException $e) {
            return back()->with('outboundError', $e->getMessage());
        }
    }

    private function owned(Request $r, string $id): ?OutboundMessage
    {
        return OutboundMessage::query()->whereKey($id)->where('user_id', $r->user()->id)->where('state', 'draft')->whereNull('draft_deleted_at')->first();
    }

    /** @return array<string, mixed> */
    private function input(Request $r): array
    {
        return $r->only(['inbox_id', 'operation', 'source_email_id', 'to', 'cc', 'bcc', 'subject', 'text_body', 'html_body', 'attachment_ids', 'sender_profile_id', 'from_display_name']);
    }
}
