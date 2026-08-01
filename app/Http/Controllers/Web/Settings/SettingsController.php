<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

abstract class SettingsController extends Controller
{
    protected function noStore(Response|RedirectResponse|View $response): mixed
    {
        if ($response instanceof Response) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function settingsView(string $section, array $data = []): View
    {
        return view('settings.'.$section, array_merge([
            'settingsSection' => $section,
            'settingsNav' => $this->navigation(),
        ], $data));
    }

    /**
     * @return list<array{key: string, label: string, route: string}>
     */
    protected function navigation(): array
    {
        $items = [
            ['key' => 'index', 'label' => 'Overview', 'route' => 'settings.index'],
            ['key' => 'profile', 'label' => 'Profile', 'route' => 'settings.profile'],
            ['key' => 'security', 'label' => 'Security', 'route' => 'settings.security'],
            ['key' => 'sessions', 'label' => 'Sessions', 'route' => 'settings.sessions'],
            ['key' => 'notifications', 'label' => 'Notifications', 'route' => 'settings.notifications'],
            ['key' => 'api-keys', 'label' => 'API Keys', 'route' => 'settings.api-keys'],
            ['key' => 'billing', 'label' => 'Billing', 'route' => 'settings.billing'],
            ['key' => 'privacy', 'label' => 'Privacy', 'route' => 'settings.privacy'],
            ['key' => 'account', 'label' => 'Account', 'route' => 'settings.account'],
        ];

        if (config('affiliates.enabled') === true) {
            $items[] = ['key' => 'affiliate', 'label' => 'Affiliate', 'route' => 'settings.affiliate'];
        }

        return $items;
    }
}
