<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Identity\SessionManagementService;
use App\Services\Settings\SettingsAnalyticsRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class SettingsSessionController extends SettingsController
{
    public function index(
        Request $request,
        SessionManagementService $sessions,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'sessions']);

        $loginQuery = LoginAttempt::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('occurred_at');

        if ($request->filled('success')) {
            $loginQuery->where('success', $request->boolean('success'));
        }
        if ($request->filled('from')) {
            $loginQuery->where('occurred_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $loginQuery->where('occurred_at', '<=', $request->date('to')->endOfDay());
        }

        return $this->settingsView('sessions', [
            'sessions' => $sessions->listForUser($user, $request->session()->getId()),
            'enumerationSupported' => $sessions->supportsEnumeration(),
            'loginAttempts' => $loginQuery->cursorPaginate(20)->withQueryString(),
            'loginHistoryRetentionDays' => (int) config('identity.prune.login_history_days', 90),
        ]);
    }

    public function destroy(
        Request $request,
        string $sessionId,
        SessionManagementService $sessions,
        AuditLogWriter $audit,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        $ok = Hash::check((string) $request->input('password'), $user->password);
        $sessions->revokeOne($user, $sessionId, (string) $request->session()->getId(), $ok);
        $audit->write('settings.session_revoked', (string) $user->getKey(), $user, metadata: [
            'scope' => 'one',
        ]);

        return back()->with('settingsStatus', __('Session revoked.'));
    }

    public function destroyOthers(
        Request $request,
        SessionManagementService $sessions,
        AuditLogWriter $audit,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        $ok = Hash::check((string) $request->input('password'), $user->password);
        $count = $sessions->revokeOthers($user, (string) $request->session()->getId(), $ok);
        $audit->write('settings.sessions_revoked', (string) $user->getKey(), $user, metadata: [
            'scope' => 'others',
            'count' => $count,
        ]);

        return back()->with('settingsStatus', __(':count other session(s) revoked.', ['count' => $count]));
    }
}
