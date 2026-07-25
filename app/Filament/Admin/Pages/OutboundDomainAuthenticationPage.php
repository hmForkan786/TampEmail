<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Domain;
use App\Models\OutboundDomainAuthentication;
use App\Models\User;
use App\Services\Outbound\OutboundDomainAuthenticationService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class OutboundDomainAuthenticationPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Outbound Domain Auth';

    protected string $view = 'filament.admin.pages.outbound-domain-authentication';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return 'Domain Auth';
    }

    public function recheck(string $domainId): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $domain = Domain::query()->find($domainId);
        if ($domain === null) {
            Notification::make()->title('Domain not found')->danger()->send();

            return;
        }

        try {
            $auth = app(OutboundDomainAuthenticationService::class)
                ->manualRecheck($domain, (string) $actor->getKey());
            Notification::make()
                ->title('Recheck complete: '.$auth->state->value)
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            $code = property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'recheck_failed';
            Notification::make()->title('Recheck failed: '.$code)->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        $service = app(OutboundDomainAuthenticationService::class);
        $rows = Domain::query()
            ->where('outbound_enabled', true)
            ->orderBy('domain')
            ->limit(100)
            ->get()
            ->map(function (Domain $domain) use ($service): array {
                $auth = OutboundDomainAuthentication::query()
                    ->where('domain_id', $domain->getKey())
                    ->where('provider', (string) config('outbound.provider', 'generic'))
                    ->first() ?? $service->ensureRecord($domain);
                $expected = $service->expectedRecordsFor($domain);

                return [
                    'id' => (string) $domain->getKey(),
                    'domain' => $domain->domain,
                    'provider' => $auth->provider,
                    'state' => $auth->state->value,
                    'ownership' => $auth->ownership_state->value,
                    'spf' => $auth->spf_state->value,
                    'dkim' => $auth->dkim_state->value,
                    'dmarc' => $auth->dmarc_state->value,
                    'failure_code' => $auth->failure_code,
                    'last_checked_at' => $auth->last_checked_at?->toIso8601String(),
                    'expected_spf' => $expected['spf'],
                    'expected_dkim' => $expected['dkim'],
                    'expected_ownership' => $expected['ownership'],
                    'expected_dmarc' => $expected['dmarc'] ?? null,
                ];
            })
            ->all();

        return ['rows' => $rows];
    }
}
