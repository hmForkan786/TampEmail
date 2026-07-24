<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BackupRestoreReadinessService
{
    public const MANIFEST_PATH = 'backup-restore/manifest.json';

    /**
     * @return array{
     *     status: 'ready'|'degraded'|'failed',
     *     issues: list<string>,
     *     database: array{configured: bool, reachable: bool, driver: string},
     *     storage: array{attachments: array<string, mixed>, message_bodies: array<string, mixed>},
     *     manifest: array{present: bool, integrity: 'ok'|'mismatch'|'missing'|'invalid'}
     * }
     */
    public function report(): array
    {
        $issues = [];

        $database = $this->evaluateDatabase($issues);
        $attachments = $this->evaluateStorageDisk(
            (string) config('platform.storage.attachments_disk', 'attachments'),
            $issues,
        );
        $messageBodies = $this->evaluateStorageDisk(
            (string) config('platform.storage.message_bodies_disk', 'message_bodies'),
            $issues,
        );
        $manifest = $this->evaluateManifest($issues);

        $failed = array_intersect($issues, [
            'database_misconfigured',
            'database_unreachable',
            'storage_misconfigured',
            'storage_not_private',
            'storage_unreachable',
            'storage_integrity_failed',
            'backup_manifest_integrity_mismatch',
            'backup_manifest_invalid',
        ]) !== [];

        $status = $failed ? 'failed' : ($issues === [] ? 'ready' : 'degraded');

        return [
            'status' => $status,
            'issues' => array_values(array_unique($issues)),
            'database' => $database,
            'storage' => [
                'attachments' => $attachments,
                'message_bodies' => $messageBodies,
            ],
            'manifest' => $manifest,
        ];
    }

    /**
     * @param  list<string>  $issues
     * @return array{configured: bool, reachable: bool, driver: string}
     */
    private function evaluateDatabase(array &$issues): array
    {
        $connection = (string) config('database.default', '');
        $driver = is_string(config("database.connections.{$connection}.driver"))
            ? (string) config("database.connections.{$connection}.driver")
            : '';

        if ($connection === '' || $driver === '' || ! is_array(config("database.connections.{$connection}"))) {
            $issues[] = 'database_misconfigured';

            return ['configured' => false, 'reachable' => false, 'driver' => 'unknown'];
        }

        try {
            DB::connection($connection)->select('select 1 as backup_restore_probe');

            return ['configured' => true, 'reachable' => true, 'driver' => $driver];
        } catch (Throwable) {
            $issues[] = 'database_unreachable';

            return ['configured' => true, 'reachable' => false, 'driver' => $driver];
        }
    }

    /**
     * @param  list<string>  $issues
     * @return array{configured: bool, reachable: bool, visibility: string, integrity: 'ok'|'failed'|'skipped'}
     */
    private function evaluateStorageDisk(string $disk, array &$issues): array
    {
        $diskConfig = config("filesystems.disks.{$disk}");

        if ($disk === '' || ! is_array($diskConfig)) {
            $issues[] = 'storage_misconfigured';

            return [
                'configured' => false,
                'reachable' => false,
                'visibility' => 'unknown',
                'integrity' => 'skipped',
            ];
        }

        $visibility = is_string($diskConfig['visibility'] ?? null)
            ? (string) $diskConfig['visibility']
            : 'unknown';

        if ($visibility !== 'private') {
            $issues[] = 'storage_not_private';
        }

        try {
            $filesystem = Storage::disk($disk);
            $probePath = '.backup-restore-probe/'.bin2hex(random_bytes(8)).'.bin';
            $payload = random_bytes(32);
            $expected = hash('sha256', $payload);

            $filesystem->put($probePath, $payload);
            $read = $filesystem->get($probePath);
            $filesystem->delete($probePath);
            $this->cleanupProbeDirectory($filesystem, '.backup-restore-probe');

            if (! is_string($read) || hash('sha256', $read) !== $expected) {
                $issues[] = 'storage_integrity_failed';

                return [
                    'configured' => true,
                    'reachable' => true,
                    'visibility' => $visibility,
                    'integrity' => 'failed',
                ];
            }

            return [
                'configured' => true,
                'reachable' => true,
                'visibility' => $visibility,
                'integrity' => 'ok',
            ];
        } catch (Throwable) {
            $issues[] = 'storage_unreachable';

            return [
                'configured' => true,
                'reachable' => false,
                'visibility' => $visibility,
                'integrity' => 'failed',
            ];
        }
    }

    /**
     * @param  list<string>  $issues
     * @return array{present: bool, integrity: 'ok'|'mismatch'|'missing'|'invalid'}
     */
    private function evaluateManifest(array &$issues): array
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists(self::MANIFEST_PATH)) {
                $issues[] = 'backup_manifest_missing';

                return ['present' => false, 'integrity' => 'missing'];
            }

            $raw = $disk->get(self::MANIFEST_PATH);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1 || ! is_array($decoded['files'] ?? null)) {
                $issues[] = 'backup_manifest_invalid';

                return ['present' => true, 'integrity' => 'invalid'];
            }

            /** @var list<mixed> $files */
            $files = $decoded['files'];

            if ($files === []) {
                $issues[] = 'backup_manifest_invalid';

                return ['present' => true, 'integrity' => 'invalid'];
            }

            foreach ($files as $file) {
                if (! is_array($file)) {
                    $issues[] = 'backup_manifest_invalid';

                    return ['present' => true, 'integrity' => 'invalid'];
                }

                $relative = is_string($file['path'] ?? null) ? (string) $file['path'] : '';
                $expected = is_string($file['sha256'] ?? null) ? strtolower((string) $file['sha256']) : '';

                if ($relative === '' || $expected === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                    $issues[] = 'backup_manifest_invalid';

                    return ['present' => true, 'integrity' => 'invalid'];
                }

                $artifactPath = 'backup-restore/'.$relative;

                if (! $disk->exists($artifactPath)) {
                    $issues[] = 'backup_manifest_integrity_mismatch';

                    return ['present' => true, 'integrity' => 'mismatch'];
                }

                $contents = $disk->get($artifactPath);

                if (! is_string($contents) || hash('sha256', $contents) !== $expected) {
                    $issues[] = 'backup_manifest_integrity_mismatch';

                    return ['present' => true, 'integrity' => 'mismatch'];
                }
            }

            return ['present' => true, 'integrity' => 'ok'];
        } catch (Throwable) {
            $issues[] = 'backup_manifest_invalid';

            return ['present' => false, 'integrity' => 'invalid'];
        }
    }

    private function cleanupProbeDirectory(Filesystem $filesystem, string $directory): void
    {
        try {
            if (method_exists($filesystem, 'deleteDirectory')) {
                $filesystem->deleteDirectory($directory);
            }
        } catch (Throwable) {
            // Probe cleanup is best-effort and must not affect readiness status.
        }
    }
}
