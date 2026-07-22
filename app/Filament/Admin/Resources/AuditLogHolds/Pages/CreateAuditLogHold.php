<?php
declare(strict_types=1);
namespace App\Filament\Admin\Resources\AuditLogHolds\Pages;
use App\Actions\AuditLog\CreateAuditLogHoldAction;
use App\DTOs\AuditLog\CreateAuditLogHoldData;
use App\Filament\Admin\Resources\AuditLogHolds\AuditLogHoldResource;
use App\Models\AuditLogHold;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
class CreateAuditLogHold extends CreateRecord
{
    protected static string $resource = AuditLogHoldResource::class;
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        return app(CreateAuditLogHoldAction::class)->execute(new CreateAuditLogHoldData((string)$data['audit_log_id'], (string)$actor->getKey(), (string)$data['reason'], isset($data['held_until']) && $data['held_until'] !== null ? \Carbon\Carbon::parse($data['held_until']) : null));
    }
}
