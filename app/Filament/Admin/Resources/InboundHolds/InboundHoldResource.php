<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundHolds;

use App\Filament\Admin\Resources\InboundHolds\Pages\CreateInboundHold;
use App\Filament\Admin\Resources\InboundHolds\Pages\ListInboundHolds;
use App\Filament\Admin\Resources\InboundHolds\Pages\ViewInboundHold;
use App\Models\Attachment;
use App\Models\InboundHold;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InboundHoldResource extends Resource
{
    protected static ?string $model = InboundHold::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 26;

    public static function getNavigationLabel(): string
    {
        return 'Inbound Holds';
    }

    public static function getModelLabel(): string
    {
        return 'Inbound Hold';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Inbound Holds';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('target_type')
                ->label('Target type')
                ->options([
                    'email' => 'Email',
                    'attachment' => 'Attachment',
                    'inbox' => 'Inbox',
                ])
                ->required()
                ->native(false),
            TextInput::make('target_id')
                ->label('Target ID')
                ->required()
                ->uuid()
                ->helperText('UUID of the email, attachment, or inbox to hold.'),
            Textarea::make('reason')
                ->required()
                ->maxLength(500)
                ->helperText('Required reason; maximum 500 characters.'),
            DateTimePicker::make('held_until')
                ->nullable()
                ->helperText('Leave empty for an indefinite hold.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('target_type')->label('Target type')->formatStateUsing(fn (?string $state): string => match ($state) {
                'email' => 'Email',
                'attachment' => 'Attachment',
                'inbox' => 'Inbox',
                default => (string) $state,
            }),
            TextEntry::make('target_id')->label('Target ID'),
            TextEntry::make('heldBy.email')->label('Held by'),
            TextEntry::make('reason'),
            TextEntry::make('held_until')->dateTime()->placeholder('Indefinite'),
            TextEntry::make('status')
                ->state(fn (InboundHold $hold): string => self::status($hold))
                ->badge(),
            TextEntry::make('parent_email_hold')
                ->label('Parent email hold')
                ->state(fn (InboundHold $hold): string => self::parentEmailHoldIndicator($hold)),
            TextEntry::make('child_attachment_protection')
                ->label('Child attachment protection')
                ->state(fn (InboundHold $hold): string => self::childAttachmentProtectionIndicator($hold)),
            TextEntry::make('releasedBy.email')->label('Released by')->placeholder('—'),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('released_at')->dateTime()->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('target_type')
                    ->label('Target type')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'email' => 'Email',
                        'attachment' => 'Attachment',
                        'inbox' => 'Inbox',
                        default => (string) $state,
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_id')->label('Target ID')->searchable(),
                TextColumn::make('heldBy.email')->label('Held by')->searchable(),
                TextColumn::make('reason')->limit(60)->searchable(),
                TextColumn::make('status')
                    ->state(fn (InboundHold $hold): string => self::status($hold))
                    ->badge(),
                TextColumn::make('held_until')->dateTime()->placeholder('Indefinite')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('target_type')
                    ->label('Target type')
                    ->options([
                        'email' => 'Email',
                        'attachment' => 'Attachment',
                        'inbox' => 'Inbox',
                    ]),
                Filter::make('target_id')
                    ->label('Target ID')
                    ->schema([
                        TextInput::make('value')->label('Target ID'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('target_id', $data['value'])
                        : $query),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'released' => 'Released',
                        'expired' => 'Expired',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->active(),
                        'released' => $query->whereNotNull('released_at'),
                        'expired' => $query->whereNull('released_at')->whereNotNull('held_until')->where('held_until', '<=', now()),
                        default => $query,
                    }),
                SelectFilter::make('held_by_user_id')
                    ->label('Held by')
                    ->relationship('heldBy', 'email')
                    ->searchable()
                    ->preload(),
                Filter::make('held_until')
                    ->label('Expiry range')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, mixed $date): Builder => $query->whereDate('held_until', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, mixed $date): Builder => $query->whereDate('held_until', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['heldBy', 'releasedBy']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundHolds::route('/'),
            'create' => CreateInboundHold::route('/create'),
            'view' => ViewInboundHold::route('/{record}'),
        ];
    }

    public static function status(InboundHold $hold): string
    {
        if ($hold->released_at !== null) {
            return 'Released';
        }

        if (! $hold->isActive()) {
            return 'Expired';
        }

        return $hold->held_until === null ? 'Indefinite' : 'Active';
    }

    public static function parentEmailHoldIndicator(InboundHold $hold): string
    {
        if ($hold->target_type !== 'attachment') {
            return $hold->target_type === 'email' ? 'N/A — email target' : 'N/A';
        }

        $attachment = Attachment::query()->find($hold->target_id);

        if ($attachment === null) {
            return 'Unknown';
        }

        $parentHeld = InboundHold::query()
            ->where('target_type', 'email')
            ->where('target_id', $attachment->email_id)
            ->active()
            ->exists();

        return $parentHeld ? 'Yes' : 'No';
    }

    public static function childAttachmentProtectionIndicator(InboundHold $hold): string
    {
        if ($hold->target_type === 'email' && $hold->isActive()) {
            return 'Yes';
        }

        if ($hold->target_type === 'attachment' && $hold->isActive()) {
            $attachment = Attachment::query()->find($hold->target_id);
            if ($attachment === null) {
                return 'Yes — direct hold';
            }

            $parentHeld = InboundHold::query()
                ->where('target_type', 'email')
                ->where('target_id', $attachment->email_id)
                ->active()
                ->exists();

            return $parentHeld ? 'Yes — parent email hold also protects this attachment' : 'Yes — direct hold';
        }

        return 'No';
    }
}
