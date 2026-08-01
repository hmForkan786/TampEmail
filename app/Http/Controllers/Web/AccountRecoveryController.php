<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\AccountRecoveryReasonCode;
use App\Http\Controllers\Controller;
use App\Services\Identity\AccountRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AccountRecoveryController extends Controller
{
    public function create(): View
    {
        return view('account.recovery', [
            'reasons' => AccountRecoveryReasonCode::labels(),
        ]);
    }

    public function store(Request $request, AccountRecoveryService $recovery): RedirectResponse
    {
        $validated = $request->validate([
            'claimed_email' => ['required', 'email'],
            'reason_code' => ['required', 'string'],
            'new_email' => ['sometimes', 'nullable', 'email'],
            'evidence_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $recovery->submit($validated, $request->ip());

        return back()->with('identityStatus', __('If we can help, your recovery request has been received and will be reviewed.'));
    }
}
