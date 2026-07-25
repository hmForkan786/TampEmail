<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\OutboundCanarySubjectType;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundLaunchCanary;
use App\Models\User;
use App\Services\Outbound\OutboundCanaryService;
use App\Services\Outbound\OutboundLaunchConfigValidator;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundLaunchReadinessService;
use App\Services\Outbound\OutboundLaunchRecommendationService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Staged outbound launch control room: rollout mode/percent, emergency
 * stop, canary membership, readiness, and pause-recommendation metrics.
 *
 * State changes always re-check platform-admin authorization inside the
 * handler (defense in depth beyond {@see canAccess()}) and use
 * `wire:confirm` in the view for an explicit confirmation step before any
 * mutation runs.
 */
final class OutboundLaunchControlPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 32;

    protected static ?string $title = 'Outbound Launch Control';

    protected string $view = 'filament.admin.pages.outbound-launch-control';

    public string $rollout_mode = 'disabled';

    public int $rollout_percent = 0;

    public bool $emergency_stop = true;

    public string $canary_subject_type = 'user';

    public ?string $canary_subject_id = null;

    public ?string $canary_label = null;

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
        return 'Outbound Launch Control';
    }

    public function mount(): void
    {
        $launchControl = app(OutboundLaunchControlService::class);
        $this->rollout_mode = $launchControl->mode();
        $this->rollout_percent = $launchControl->percent();
        $this->emergency_stop = $launchControl->isEmergencyStopped();
    }

    public function updateRollout(): void
    {
        $actor = $this->currentAdmin();
        if ($actor === null) {
            return;
        }

        $this->validate([
            'rollout_mode' => ['required', 'in:disabled,canary,percentage,enabled'],
            'rollout_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        try {
            app(OutboundLaunchControlService::class)->setRollout(
                $this->rollout_mode,
                $this->rollout_percent,
                $actor,
                app(OutboundLaunchConfigValidator::class),
            );
        } catch (OutboundSendException $exception) {
            Notification::make()->title('Rollout update rejected')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Rollout updated')->success()->send();
    }

    public function toggleEmergencyStop(): void
    {
        $actor = $this->currentAdmin();
        if ($actor === null) {
            return;
        }

        app(OutboundLaunchControlService::class)->setEmergencyStop($this->emergency_stop, $actor);
        Notification::make()->title($this->emergency_stop ? 'Emergency stop engaged' : 'Emergency stop lifted')->success()->send();
    }

    public function clearOverrides(): void
    {
        $actor = $this->currentAdmin();
        if ($actor === null) {
            return;
        }

        $launchControl = app(OutboundLaunchControlService::class);
        $launchControl->clearOverrides($actor);
        $this->rollout_mode = $launchControl->mode();
        $this->rollout_percent = $launchControl->percent();
        $this->emergency_stop = $launchControl->isEmergencyStopped();
        Notification::make()->title('Live overrides cleared')->success()->send();
    }

    public function addCanary(): void
    {
        $actor = $this->currentAdmin();
        if ($actor === null) {
            return;
        }

        $this->validate([
            'canary_subject_type' => ['required', 'in:user,inbox,domain,api_key'],
            'canary_subject_id' => ['required', 'uuid'],
            'canary_label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            app(OutboundCanaryService::class)->add(
                OutboundCanarySubjectType::from($this->canary_subject_type),
                (string) $this->canary_subject_id,
                $actor,
                $this->canary_label,
            );
        } catch (OutboundSendException $exception) {
            Notification::make()->title('Could not add canary')->body($exception->getMessage())->danger()->send();

            return;
        }

        $this->reset(['canary_subject_id', 'canary_label']);
        Notification::make()->title('Canary added')->success()->send();
    }

    public function removeCanary(string $id): void
    {
        $actor = $this->currentAdmin();
        if ($actor === null) {
            return;
        }

        $canary = OutboundLaunchCanary::query()->find($id);
        if ($canary === null) {
            return;
        }

        app(OutboundCanaryService::class)->remove($canary, $actor);
        Notification::make()->title('Canary removed')->success()->send();
    }

    protected function getViewData(): array
    {
        $readiness = $this->safeArray(fn (): array => app(OutboundLaunchReadinessService::class)->evaluate());
        $recommendation = $this->safeArray(fn (): array => app(OutboundLaunchRecommendationService::class)->recommend());

        $canaries = OutboundLaunchCanary::query()
            ->active()
            ->orderByDesc('added_at')
            ->limit(100)
            ->get()
            ->map(static fn (OutboundLaunchCanary $canary): array => [
                'id' => (string) $canary->getKey(),
                'subject_type' => $canary->subject_type->value,
                'subject_id' => (string) $canary->subject_id,
                'label' => $canary->label,
                'added_at' => $canary->added_at?->toIso8601String(),
            ])
            ->all();

        return [
            'readiness' => $readiness,
            'recommendation' => $recommendation,
            'canaries' => $canaries,
            'has_overrides' => app(OutboundLaunchControlService::class)->hasLiveOverrides(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeArray(\Closure $resolver): array
    {
        try {
            return $resolver();
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'recommendation' => 'unavailable'];
        }
    }

    private function currentAdmin(): ?User
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return null;
        }

        return $actor;
    }
}
