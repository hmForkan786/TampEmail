<?php
namespace App\Filament\Admin\Resources\MailServers\Pages;
use App\Filament\Admin\Resources\MailServers\MailServerResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;
class ListMailServers extends ListRecords { protected static string $resource = MailServerResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
