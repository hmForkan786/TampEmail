<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Exceptions\OutboundSendException;
use App\Http\Controllers\Controller;
use App\Models\Inbox;
use App\Services\Outbound\OutboundSenderProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OutboundSenderProfileController extends Controller
{
    public function __construct(
        private readonly OutboundSenderProfileService $profiles,
    ) {}

    public function index(Request $request): View
    {
        return view('outbound-sender-profiles.index', [
            'profiles' => $this->profiles->list($request->user()),
            'inboxes' => Inbox::query()->where('user_id', $request->user()->id)->where('is_active', true)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->profiles->create($request->user(), $this->profileInput($request));
        } catch (OutboundSendException $e) {
            return back()->withInput()->with('outboundError', $e->getMessage());
        }

        return back()->with('outboundStatus', 'Sender profile created.');
    }

    public function edit(Request $request, string $profile): View|RedirectResponse
    {
        try {
            $owned = $this->profiles->findOwned($request->user(), $profile);
        } catch (OutboundSendException $e) {
            return redirect()->route('outbound-sender-profiles.index')->with('outboundError', $e->getMessage());
        }

        return view('outbound-sender-profiles.edit', [
            'profile' => $owned,
            'inboxes' => Inbox::query()->where('user_id', $request->user()->id)->where('is_active', true)->get(),
        ]);
    }

    public function update(Request $request, string $profile): RedirectResponse
    {
        try {
            $input = $this->profileInput($request);
            $input['is_active'] = $request->boolean('is_active');
            $this->profiles->update(
                $request->user(),
                $profile,
                $input,
                (int) $request->input('version') ?: null,
            );
        } catch (OutboundSendException $e) {
            return back()->withInput()->with('outboundError', $e->getMessage());
        }

        return redirect()->route('outbound-sender-profiles.index')->with('outboundStatus', 'Sender profile updated.');
    }

    public function destroy(Request $request, string $profile): RedirectResponse
    {
        try {
            $this->profiles->delete($request->user(), $profile, (int) $request->input('version') ?: null);
        } catch (OutboundSendException $e) {
            return back()->with('outboundError', $e->getMessage());
        }

        return back()->with('outboundStatus', 'Sender profile deleted.');
    }

    public function makeDefault(Request $request, string $profile): RedirectResponse
    {
        try {
            $this->profiles->makeDefault($request->user(), $profile, (int) $request->input('version'));
        } catch (OutboundSendException $e) {
            return back()->with('outboundError', $e->getMessage());
        }

        return back()->with('outboundStatus', 'Default sender profile updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function profileInput(Request $request): array
    {
        return [
            'inbox_id' => $request->input('inbox_id'),
            'name' => $request->input('name'),
            'display_name' => $request->input('display_name'),
            'reply_to_address' => $request->input('reply_to_address'),
            'reply_to_name' => $request->input('reply_to_name'),
            'signature_text' => $request->input('signature_text'),
            'signature_html' => $request->input('signature_html'),
            'include_on_send' => $request->boolean('include_on_send'),
            'include_on_reply' => $request->boolean('include_on_reply'),
            'include_on_forward' => $request->boolean('include_on_forward'),
        ];
    }
}
