<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Models\User;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\UserSettingsSummaryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsDashboardController extends SettingsController
{
    public function __invoke(Request $request, UserSettingsSummaryService $summary, SettingsAnalyticsRecorder $analytics): View
    {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'dashboard']);

        return $this->settingsView('index', [
            'summary' => $summary->dashboard($user, $request->session()->getId()),
        ]);
    }
}
