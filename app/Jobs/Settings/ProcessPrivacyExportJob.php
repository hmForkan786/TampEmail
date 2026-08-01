<?php

declare(strict_types=1);

namespace App\Jobs\Settings;

use App\Enums\PrivacyExportStatus;
use App\Models\UserPrivacyExport;
use App\Notifications\Settings\PrivacyExportReadyNotification;
use App\Services\Settings\PrivacyPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessPrivacyExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $exportId) {}

    public function handle(PrivacyPreferenceService $privacy): void
    {
        /** @var UserPrivacyExport|null $export */
        $export = UserPrivacyExport::query()->with('user')->find($this->exportId);
        if ($export === null || $export->user === null) {
            return;
        }

        if (! in_array($export->status, [PrivacyExportStatus::Pending, PrivacyExportStatus::Processing], true)) {
            return;
        }

        $export->forceFill(['status' => PrivacyExportStatus::Processing])->save();

        try {
            $payload = $privacy->buildArchivePayload($export->user);
            $disk = (string) config('settings.privacy.export.disk', 'local');
            $directory = trim((string) config('settings.privacy.export.directory', 'private/settings/exports'), '/');
            $path = $directory.'/'.$export->user_id.'/'.$export->getKey().'.json';

            Storage::disk($disk)->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            $export->forceFill([
                'status' => PrivacyExportStatus::Ready,
                'disk' => $disk,
                'path' => $path,
                'ready_at' => now(),
                'expires_at' => now()->addHours((int) config('settings.privacy.export.ttl_hours', 48)),
                'failure_reason' => null,
            ])->save();

            $export->user->notify(new PrivacyExportReadyNotification((string) $export->getKey()));
        } catch (Throwable $exception) {
            $export->forceFill([
                'status' => PrivacyExportStatus::Failed,
                'failure_reason' => 'export_failed',
            ])->save();

            throw $exception;
        }
    }
}
