<?php

declare(strict_types=1);

use App\Services\Ops\BackupRestoreReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function seedValidBackupManifest(string $payload = 'restore-marker'): void
{
    Storage::disk('local')->put('backup-restore/marker.txt', $payload);
    Storage::disk('local')->put('backup-restore/manifest.json', json_encode([
        'version' => 1,
        'files' => [
            [
                'path' => 'marker.txt',
                'sha256' => hash('sha256', $payload),
            ],
        ],
    ], JSON_THROW_ON_ERROR));
}

function assertBackupRestoreOutputSafe(string $output): void
{
    expect($output)
        ->not->toContain('password')
        ->not->toContain('secret')
        ->not->toContain('127.0.0.1')
        ->not->toContain('AWS_')
        ->not->toContain('storage/app')
        ->not->toContain('C:\\')
        ->not->toContain('/var/')
        ->not->toContain('SQLSTATE')
        ->not->toContain('stack trace');
}

it('reports ready for healthy database storage and valid manifest', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::fake('message_bodies');
    config([
        'platform.storage.attachments_disk' => 'attachments',
        'platform.storage.message_bodies_disk' => 'message_bodies',
        'filesystems.disks.attachments.visibility' => 'private',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);
    seedValidBackupManifest();

    $beforeAttachments = Storage::disk('attachments')->allFiles();
    $beforeBodies = Storage::disk('message_bodies')->allFiles();
    $tablesBefore = DB::table('users')->count();

    expect(Artisan::call('backup:restore-health', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('ready')
        ->and($json['issues'])->toBe([])
        ->and($json['database']['reachable'])->toBeTrue()
        ->and($json['storage']['attachments']['integrity'])->toBe('ok')
        ->and($json['storage']['message_bodies']['integrity'])->toBe('ok')
        ->and($json['manifest']['present'])->toBeTrue()
        ->and($json['manifest']['integrity'])->toBe('ok')
        ->and(Storage::disk('attachments')->allFiles())->toBe($beforeAttachments)
        ->and(Storage::disk('message_bodies')->allFiles())->toBe($beforeBodies)
        ->and(DB::table('users')->count())->toBe($tablesBefore);

    assertBackupRestoreOutputSafe($output);
});

it('reports degraded when the backup manifest is missing', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::fake('message_bodies');
    config([
        'filesystems.disks.attachments.visibility' => 'private',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);

    expect(Artisan::call('backup:restore-health', ['--json' => true]))->toBe(2);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('degraded')
        ->and($json['issues'])->toContain('backup_manifest_missing')
        ->and($json['manifest']['integrity'])->toBe('missing');

    assertBackupRestoreOutputSafe($output);
});

it('reports failed when storage disk is missing', function (): void {
    Storage::fake('local');
    Storage::fake('message_bodies');
    config([
        'platform.storage.attachments_disk' => 'missing-attachments-disk',
        'platform.storage.message_bodies_disk' => 'message_bodies',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);
    seedValidBackupManifest();

    expect(Artisan::call('backup:restore-health', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('failed')
        ->and($json['issues'])->toContain('storage_misconfigured')
        ->and($json['storage']['attachments']['configured'])->toBeFalse();

    assertBackupRestoreOutputSafe($output);
});

it('reports failed for invalid database configuration', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::fake('message_bodies');
    config([
        'filesystems.disks.attachments.visibility' => 'private',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);
    seedValidBackupManifest();

    $previousDefault = config('database.default');

    try {
        config(['database.default' => '']);

        expect(Artisan::call('backup:restore-health', ['--json' => true]))->toBe(1);
        $output = Artisan::output();
        $json = json_decode($output, true);

        expect($json['status'])->toBe('failed')
            ->and($json['issues'])->toContain('database_misconfigured')
            ->and($json['database']['configured'])->toBeFalse()
            ->and($json['database']['driver'])->toBe('unknown');

        assertBackupRestoreOutputSafe($output);
    } finally {
        config(['database.default' => $previousDefault]);
    }
});

it('reports failed when manifest integrity mismatches', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::fake('message_bodies');
    config([
        'filesystems.disks.attachments.visibility' => 'private',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);
    Storage::disk('local')->put('backup-restore/marker.txt', 'actual-bytes');
    Storage::disk('local')->put('backup-restore/manifest.json', json_encode([
        'version' => 1,
        'files' => [
            ['path' => 'marker.txt', 'sha256' => hash('sha256', 'expected-bytes')],
        ],
    ], JSON_THROW_ON_ERROR));

    expect(Artisan::call('backup:restore-health', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('failed')
        ->and($json['issues'])->toContain('backup_manifest_integrity_mismatch')
        ->and($json['manifest']['integrity'])->toBe('mismatch');

    assertBackupRestoreOutputSafe($output);
});

it('exposes safe human output and deterministic exit codes', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::fake('message_bodies');
    config([
        'filesystems.disks.attachments.visibility' => 'private',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);
    seedValidBackupManifest();

    expect(Artisan::call('backup:restore-health'))->toBe(0);
    $readyOutput = Artisan::output();
    expect($readyOutput)->toContain('ready')->and($readyOutput)->toContain('status');
    assertBackupRestoreOutputSafe($readyOutput);

    Storage::disk('local')->delete('backup-restore/manifest.json');
    expect(Artisan::call('backup:restore-health'))->toBe(2);
    $degradedOutput = Artisan::output();
    expect($degradedOutput)->toContain('degraded');
    assertBackupRestoreOutputSafe($degradedOutput);

    config(['platform.storage.attachments_disk' => 'missing-attachments-disk']);
    expect(Artisan::call('backup:restore-health'))->toBe(1);
    $failedOutput = Artisan::output();
    expect($failedOutput)->toContain('failed');
    assertBackupRestoreOutputSafe($failedOutput);
});

it('returns bounded failure output when readiness throws', function (): void {
    app()->instance(BackupRestoreReadinessService::class, new class extends BackupRestoreReadinessService
    {
        public function report(): array
        {
            throw new RuntimeException('mysql://user:password@127.0.0.1/db at C:\\app\\secret.php');
        }
    });

    expect(Artisan::call('backup:restore-health', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('failed')
        ->and($json['issues'])->toContain('backup_restore_readiness_unavailable');

    assertBackupRestoreOutputSafe($output);
});

it('does not mutate production data during readiness checks', function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    Storage::fake('message_bodies');
    config([
        'filesystems.disks.attachments.visibility' => 'private',
        'filesystems.disks.message_bodies.visibility' => 'private',
    ]);
    seedValidBackupManifest('immutable-marker');
    Storage::disk('attachments')->put('keep/me.txt', 'keep');
    Storage::disk('message_bodies')->put('keep/body.txt', 'body');

    $usersBefore = DB::table('users')->count();
    $manifestBefore = Storage::disk('local')->get('backup-restore/manifest.json');
    $attachmentBefore = Storage::disk('attachments')->get('keep/me.txt');
    $bodyBefore = Storage::disk('message_bodies')->get('keep/body.txt');

    expect(app(BackupRestoreReadinessService::class)->report()['status'])->toBe('ready');

    expect(DB::table('users')->count())->toBe($usersBefore)
        ->and(Storage::disk('local')->get('backup-restore/manifest.json'))->toBe($manifestBefore)
        ->and(Storage::disk('attachments')->get('keep/me.txt'))->toBe($attachmentBefore)
        ->and(Storage::disk('message_bodies')->get('keep/body.txt'))->toBe($bodyBefore)
        ->and(Storage::disk('attachments')->allFiles())->toBe(['keep/me.txt'])
        ->and(Storage::disk('message_bodies')->allFiles())->toBe(['keep/body.txt']);
});
