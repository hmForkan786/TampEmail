<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PrivacyExportStatus;
use App\Models\UserPrivacyExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class SettingsPruneExpiredExportsCommand extends Command
{
    protected $signature = 'settings:prune-expired-exports
                            {--dry-run : Report without deleting}
                            {--confirm : Required when prune is enabled for destructive cleanup}
                            {--batch= : Override batch size}';

    protected $description = 'Prune expired privacy export archives (feature-flagged)';

    public function handle(): int
    {
        if (config('settings.prune.enabled') !== true && ! $this->option('dry-run')) {
            $this->warn('SETTINGS_PRUNE_ENABLED is false. Use --dry-run or enable the feature flag.');

            return self::SUCCESS;
        }

        if (! $this->option('dry-run') && ! $this->option('confirm')) {
            $this->error('Refusing destructive prune without --confirm.');

            return self::FAILURE;
        }

        $batch = max(1, (int) ($this->option('batch') ?: config('settings.prune.batch_size', 100)));
        $rows = UserPrivacyExport::query()
            ->where(function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('status', PrivacyExportStatus::Ready->value)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now());
                })->orWhere(function ($inner): void {
                    $inner->where('status', PrivacyExportStatus::Downloaded->value)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now());
                });
            })
            ->orderBy('expires_at')
            ->limit($batch)
            ->get();

        $deleted = 0;
        foreach ($rows as $export) {
            if ($this->option('dry-run')) {
                $this->line('Would prune export '.$export->getKey());

                continue;
            }

            if (is_string($export->path) && $export->path !== '') {
                $disk = (string) ($export->disk ?: config('settings.privacy.export.disk', 'local'));
                Storage::disk($disk)->delete($export->path);
            }

            $export->forceFill([
                'status' => PrivacyExportStatus::Expired,
                'path' => null,
            ])->save();
            $deleted++;
        }

        $this->info(($this->option('dry-run') ? 'Matched' : 'Pruned').': '.$rows->count().' (updated '.$deleted.')');

        return self::SUCCESS;
    }
}
