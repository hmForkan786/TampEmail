<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Actions\Outbound\RetryOutboundMessageWithProviderAction;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundOpsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Includes the platform-admin-only manual provider retry (Prompt 619): the
 * only surface in the application that can resend an outbound message
 * through a provider other than the one it originally failed with. See
 * {@see RetryOutboundMessageWithProviderAction} for the full policy.
 */
final class OutboundEmailOps extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Outbound Email';

    protected string $view = 'filament.admin.pages.outbound-email-ops';

    public string $retry_message_id = '';

    public string $retry_provider = '';

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
        return 'Outbound Email';
    }

    public function retryWithProvider(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $this->validate([
            'retry_message_id' => ['required', 'uuid'],
            'retry_provider' => ['required', 'string', 'max:32'],
        ]);

        $message = OutboundMessage::query()->find($this->retry_message_id);
        if ($message === null) {
            Notification::make()->title('Message not found')->danger()->send();

            return;
        }

        try {
            app(RetryOutboundMessageWithProviderAction::class)->execute($message, $this->retry_provider, $actor);
        } catch (OutboundSendException $exception) {
            Notification::make()
                ->title('Manual provider retry denied')
                ->body($exception->errorCode)
                ->danger()
                ->send();

            return;
        }

        $this->reset(['retry_message_id', 'retry_provider']);
        Notification::make()->title('Manual provider retry completed')->success()->send();
    }

    protected function getViewData(): array
    {
        try {
            return $this->safeReport(app(OutboundOpsService::class)->report());
        } catch (\Throwable) {
            return [
                'status' => 'failed',
                'readiness' => ['state' => 'failed', 'transport' => 'unknown', 'configuration_valid' => false, 'failure_code' => 'ops_unavailable'],
                'volume' => ['last_24_hours' => [], 'last_7_days' => []],
                'retries' => [],
                'provider' => [],
                'providers' => [],
                'issues' => ['ops_unavailable'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function safeReport(array $report): array
    {
        $status = $report['status'] ?? 'failed';
        if (! in_array($status, ['healthy', 'degraded', 'failed', 'unknown'], true)) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'evaluated_at' => is_string($report['evaluated_at'] ?? null) ? $report['evaluated_at'] : null,
            'readiness' => is_array($report['readiness'] ?? null) ? $report['readiness'] : [],
            'queue' => is_array($report['queue'] ?? null) ? $report['queue'] : [],
            'volume' => is_array($report['volume'] ?? null) ? $report['volume'] : [],
            'retries' => is_array($report['retries'] ?? null) ? $report['retries'] : [],
            'provider' => is_array($report['provider'] ?? null) ? $report['provider'] : [],
            'providers' => is_array($report['providers'] ?? null) ? $report['providers'] : [],
            'suppressions' => is_array($report['suppressions'] ?? null) ? $report['suppressions'] : [],
            'abuse' => is_array($report['abuse'] ?? null) ? $report['abuse'] : [],
            'issues' => array_values(array_filter(
                is_array($report['issues'] ?? null) ? $report['issues'] : [],
                fn ($issue): bool => is_string($issue) && preg_match('/^[a-z0-9_]{1,80}$/', $issue) === 1,
            )),
        ];
    }
}
