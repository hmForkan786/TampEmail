<?php
namespace App\Filament\Admin\Resources\MailServers\Pages;
use App\Filament\Admin\Resources\MailServers\MailServerResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions\EditAction;
class ViewMailServer extends ViewRecord { protected static string $resource = MailServerResource::class; protected function getHeaderActions(): array { return [EditAction::make()]; } }
