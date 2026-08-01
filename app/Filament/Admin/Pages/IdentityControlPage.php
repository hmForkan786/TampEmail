<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\RegistrationMode;
use App\Models\AccountRecoveryRequest;
use App\Models\LoginAttempt;
use App\Models\RegistrationInvite;
use App\Models\User;
use App\Services\Identity\AccountRecoveryService;
use App\Services\Identity\IdentityHealthCheckService;
use App\Services\Identity\InviteService;
use App\Services\Identity\PasswordPolicy;
use App\Services\Identity\SessionManagementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

final class IdentityControlPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Identity';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Identity Control';

    protected static ?string $title = 'Identity Control';

    protected string $view = 'filament.admin.pages.identity-control';

    /** @var array<string, mixed> */
    public array $health = [];

    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var list<array<string, mixed>> */
    public array $pendingRecoveries = [];

    /** @var list<array<string, mixed>> */
    public array $recentLogins = [];

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $this->health = app(IdentityHealthCheckService::class)->check();
        $mode = RegistrationMode::fromConfig((string) config('identity.registration.mode'));
        $this->settings = [
            'registration_mode' => $mode->value,
            'registration_mode_label' => $mode->label(),
            'verification_required' => (bool) config('identity.registration.email_verification_required', true),
            'password_policy' => PasswordPolicy::summary(),
            'challenge_enabled' => (bool) config('identity.challenge.enabled', false),
            'max_active_web_sessions' => (int) config('identity.sessions.max_active_web_sessions', 0),
            'closure_grace_days' => (int) config('identity.closure.grace_days', 7),
            'mutable_runtime_settings' => false,
            'pending_users' => User::query()->where('status', 'pending')->count(),
            'open_invites' => RegistrationInvite::query()->whereNull('revoked_at')->whereColumn('uses', '<', 'max_uses')->count(),
        ];

        $this->pendingRecoveries = AccountRecoveryRequest::query()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AccountRecoveryRequest $r): array => [
                'id' => (string) $r->getKey(),
                'status' => $r->status->value,
                'reason' => $r->reason_code->value,
                'user_id' => $r->user_id,
                'created_at' => optional($r->created_at)?->toDateTimeString(),
            ])
            ->all();

        $this->recentLogins = LoginAttempt::query()
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn (LoginAttempt $a): array => [
                'success' => $a->success,
                'failure_reason_code' => $a->failure_reason_code,
                'occurred_at' => optional($a->occurred_at)?->toDateTimeString(),
                'user_id' => $a->user_id,
            ])
            ->all();
    }

    public function createInvite(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            return;
        }

        $this->mountAction('createInviteAction');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createInviteAction')
                ->label('Create invite')
                ->form([
                    TextInput::make('email')->email()->nullable(),
                    TextInput::make('max_uses')->numeric()->default(1)->required(),
                    DateTimePicker::make('expires_at')->nullable(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
                        return;
                    }
                    try {
                        $result = app(InviteService::class)->create(
                            $data['email'] ?? null,
                            (int) ($data['max_uses'] ?? 1),
                            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                            $actor,
                        );
                        Notification::make()
                            ->title('Invite created')
                            ->body('Token shown once: '.$result['plain_token'])
                            ->success()
                            ->persistent()
                            ->send();
                        $this->refreshState();
                    } catch (Throwable $e) {
                        Notification::make()->title('Invite failed')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('refresh')
                ->label('Refresh')
                ->action(fn () => $this->refreshState()),
        ];
    }

    public function startReview(string $id): void
    {
        $this->runRecovery($id, fn (AccountRecoveryService $s, AccountRecoveryRequest $r, User $a) => $s->startReview($r, $a), 'Review started');
    }

    public function approveRecovery(string $id): void
    {
        $this->runRecovery($id, fn (AccountRecoveryService $s, AccountRecoveryRequest $r, User $a) => $s->approve($r, $a), 'Recovery approved');
    }

    public function rejectRecovery(string $id): void
    {
        $this->runRecovery($id, fn (AccountRecoveryService $s, AccountRecoveryRequest $r, User $a) => $s->reject($r, $a), 'Recovery rejected');
    }

    public function completeRecovery(string $id): void
    {
        $this->runRecovery($id, fn (AccountRecoveryService $s, AccountRecoveryRequest $r, User $a) => $s->complete($r, $a), 'Recovery completed');
    }

    public function forceRevokeSessions(string $userId): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            return;
        }
        $user = User::query()->whereKey($userId)->first();
        if (! $user instanceof User) {
            return;
        }
        $count = app(SessionManagementService::class)->revokeAllForUser($user);
        Notification::make()->title('Sessions revoked')->body((string) $count)->success()->send();
    }

    /**
     * @param  callable(AccountRecoveryService, AccountRecoveryRequest, User): mixed  $callback
     */
    private function runRecovery(string $id, callable $callback, string $message): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            return;
        }
        $request = AccountRecoveryRequest::query()->whereKey($id)->first();
        if (! $request instanceof AccountRecoveryRequest) {
            return;
        }
        try {
            $callback(app(AccountRecoveryService::class), $request, $actor);
            Notification::make()->title($message)->success()->send();
            $this->refreshState();
        } catch (Throwable $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
