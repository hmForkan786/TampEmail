<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\OutboundRecipientSuppression;
use App\Models\User;
use App\Services\Outbound\OutboundSuppressionService;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class OutboundRecipientSuppressions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 42;

    protected static ?string $title = 'Outbound Recipient Suppressions';

    protected string $view = 'filament.admin.pages.outbound-recipient-suppressions';

    public ?string $email = null;

    public string $reason = 'manual';

    public ?string $expires_at = null;

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
        return 'Recipient Suppressions';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required()->maxLength(320),
            Select::make('reason')->options([
                'manual' => 'Manual',
                'policy' => 'Policy',
                'invalid_recipient' => 'Invalid recipient',
            ])->required(),
            DateTimePicker::make('expires_at')->native(false),
        ]);
    }

    public function addSuppression(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:320'],
            'reason' => ['required', 'in:manual,policy,invalid_recipient'],
            'expires_at' => ['nullable', 'date'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();
        app(OutboundSuppressionService::class)->suppress(
            email: (string) $this->email,
            reason: $this->reason,
            source: 'admin',
            expiresAt: $this->expires_at,
            actor: $actor,
        );

        $this->reset(['email', 'expires_at']);
        $this->reason = 'manual';
        Notification::make()->title('Recipient suppressed')->success()->send();
    }

    public function removeSuppression(string $id): void
    {
        $row = OutboundRecipientSuppression::query()->findOrFail($id);
        /** @var User $actor */
        $actor = auth()->user();
        app(OutboundSuppressionService::class)->unsuppress($row, $actor, elevatedComplaintRemoval: true);
        Notification::make()->title('Suppression removed')->success()->send();
    }

    protected function getViewData(): array
    {
        $rows = OutboundRecipientSuppression::query()
            ->latest('suppressed_at')
            ->limit(100)
            ->get()
            ->map(static fn (OutboundRecipientSuppression $row): array => [
                'id' => (string) $row->getKey(),
                'masked_recipient' => $row->masked_recipient,
                'reason' => $row->reason,
                'source' => $row->source,
                'scope_type' => $row->scope_type,
                'active' => $row->isCurrentlyActive(),
                'suppressed_at' => $row->suppressed_at?->toIso8601String(),
                'expires_at' => $row->expires_at?->toIso8601String(),
            ])
            ->all();

        return ['suppressions' => $rows];
    }
}
