<?php
declare(strict_types=1);
namespace App\Filament\Admin\Resources\AuditLogHolds;
use App\DTOs\AuditLog\CreateAuditLogHoldData;
use App\Filament\Admin\Resources\AuditLogHolds\Pages\CreateAuditLogHold;
use App\Filament\Admin\Resources\AuditLogHolds\Pages\ListAuditLogHolds;
use App\Filament\Admin\Resources\AuditLogHolds\Pages\ViewAuditLogHold;
use App\Models\AuditLogHold;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogHoldResource extends Resource
{
    protected static ?string $model = AuditLogHold::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;
    protected static string|UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 25;
    public static function getNavigationLabel(): string { return 'Audit Holds'; }
    public static function getModelLabel(): string { return 'Audit Hold'; }
    public static function getPluralModelLabel(): string { return 'Audit Holds'; }
    public static function shouldRegisterNavigation(): bool { return static::canViewAny(); }
    public static function form(Schema $schema): Schema { return $schema->components([
        Select::make('audit_log_id')->label('Target audit log')->relationship('auditLog','action')->getOptionLabelFromRecordUsing(fn ($record): string => $record->action.' — '.$record->getKey())->searchable(['action','auditable_id'])->preload()->required(),
        Textarea::make('reason')->required()->maxLength(500)->helperText('Required reason; maximum 500 characters.'),
        DateTimePicker::make('held_until')->nullable()->helperText('Leave empty for an indefinite hold.'),
    ]); }
    public static function infolist(Schema $schema): Schema { return $schema->components([
        TextEntry::make('auditLog.id')->label('Target audit log ID'), TextEntry::make('auditLog.action')->label('Action'),
        TextEntry::make('heldBy.email')->label('Held by'), TextEntry::make('reason'), TextEntry::make('held_until')->dateTime()->placeholder('Indefinite'),
        TextEntry::make('status')->state(fn (AuditLogHold $hold): string => self::status($hold))->badge(), TextEntry::make('releasedBy.email')->label('Released by')->placeholder('—'),
        TextEntry::make('created_at')->dateTime(), TextEntry::make('released_at')->dateTime()->placeholder('—'),
    ]); }
    public static function table(Table $table): Table { return $table->recordTitleAttribute('reason')->defaultSort('created_at','desc')->columns([
        TextColumn::make('auditLog.action')->label('Action')->searchable(), TextColumn::make('audit_log_id')->label('Target ID')->searchable(),
        TextColumn::make('heldBy.email')->label('Held by')->searchable(), TextColumn::make('reason')->limit(60), TextColumn::make('status')->state(fn (AuditLogHold $hold): string => self::status($hold))->badge(),
        TextColumn::make('held_until')->dateTime()->placeholder('Indefinite')->sortable(), TextColumn::make('created_at')->dateTime()->sortable(),
    ])->filters([SelectFilter::make('status')->options(['active'=>'Active','released'=>'Released','expired'=>'Expired'])->query(fn (Builder $q, array $data): Builder => match ($data['value'] ?? null) { 'active'=>$q->active(), 'released'=>$q->whereNotNull('released_at'), 'expired'=>$q->whereNull('released_at')->whereNotNull('held_until')->where('held_until','<=',now()), default=>$q })])->recordActions([ViewAction::make()])->toolbarActions([]); }
    public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->with(['auditLog','heldBy','releasedBy']); }
    public static function getRelations(): array { return []; }
    public static function getPages(): array { return ['index'=>ListAuditLogHolds::route('/'),'create'=>CreateAuditLogHold::route('/create'),'view'=>ViewAuditLogHold::route('/{record}')]; }
    public static function status(AuditLogHold $hold): string { return $hold->released_at !== null ? 'Released' : ($hold->isActive() ? 'Active' : 'Expired'); }
}
