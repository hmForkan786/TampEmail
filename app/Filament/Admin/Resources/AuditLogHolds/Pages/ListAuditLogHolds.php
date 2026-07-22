<?php
namespace App\Filament\Admin\Resources\AuditLogHolds\Pages;
use App\Filament\Admin\Resources\AuditLogHolds\AuditLogHoldResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
class ListAuditLogHolds extends ListRecords { protected static string $resource = AuditLogHoldResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
