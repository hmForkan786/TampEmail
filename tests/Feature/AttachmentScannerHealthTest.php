<?php

use App\Models\Attachment;
use App\Services\Inbound\AttachmentScannerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function bindScannerHealthProbe(?callable $probe): void
{
    app()->instance(AttachmentScannerHealthService::class, new AttachmentScannerHealthService($probe));
}

function assertScannerHealthSafe(string $output): void
{
    expect($output)
        ->not->toContain('127.0.0.1')
        ->not->toContain('password')
        ->not->toContain('secret')
        ->not->toContain('stream_socket_client')
        ->not->toContain('Connection refused');
}

it('reports healthy clamav readiness without scanning attachments', function (): void {
    config([
        'attachments.scanner_backend' => 'clamav',
        'attachments.clamav.host' => 'scanner.internal',
        'attachments.clamav.port' => 3310,
        'attachments.clamav.connect_timeout_seconds' => 5,
        'attachments.clamav.read_timeout_seconds' => 30,
        'cache.default' => 'array',
    ]);
    Cache::flush();
    Storage::fake('attachments');
    bindScannerHealthProbe(fn (): array => ['healthy' => true, 'reachable' => true, 'protocol' => 'pong']);

    $tablesBefore = DB::table('attachments')->count();
    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('healthy')
        ->and($json['backend'])->toBe('clamav')
        ->and($json['reachable'])->toBeTrue()
        ->and($json['protocol'])->toBe('pong')
        ->and($json['last_successful_check_at'])->not->toBeNull()
        ->and(Attachment::query()->count())->toBe(0)
        ->and(DB::table('attachments')->count())->toBe($tablesBefore)
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);

    assertScannerHealthSafe($output);
    expect($output)->not->toContain('scanner.internal');
});

it('reports unavailable daemon outcomes with non-zero exit', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'cache.default' => 'array']);
    bindScannerHealthProbe(fn (): array => ['healthy' => false, 'reachable' => false, 'protocol' => 'unavailable']);

    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);
    expect($json['status'])->toBe('unavailable')
        ->and($json['reachable'])->toBeFalse()
        ->and($json['protocol'])->toBe('unavailable');
    assertScannerHealthSafe($output);
});

it('reports timeout and malformed probe protocols as unavailable', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'cache.default' => 'array']);

    bindScannerHealthProbe(fn (): array => ['healthy' => false, 'reachable' => true, 'protocol' => 'timeout']);
    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true))->toMatchArray([
        'status' => 'unavailable',
        'reachable' => true,
        'protocol' => 'timeout',
    ]);

    bindScannerHealthProbe(fn (): array => ['healthy' => false, 'reachable' => true, 'protocol' => 'malformed']);
    expect(Artisan::call('attachments:scanner-health'))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('status: unavailable')
        ->and($output)->toContain('protocol: malformed');
    assertScannerHealthSafe($output);
});

it('rejects invalid clamav configuration before probing', function (): void {
    config([
        'attachments.scanner_backend' => 'clamav',
        'attachments.clamav.host' => '127.0.0.1',
        'attachments.clamav.port' => 0,
        'attachments.clamav.connect_timeout_seconds' => 5,
        'attachments.clamav.read_timeout_seconds' => 30,
        'cache.default' => 'array',
    ]);
    $probed = false;
    bindScannerHealthProbe(function () use (&$probed): array {
        $probed = true;

        return ['healthy' => true, 'reachable' => true, 'protocol' => 'pong'];
    });

    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(1);
    $json = json_decode(Artisan::output(), true);
    expect($probed)->toBeFalse()
        ->and($json['status'])->toBe('misconfigured')
        ->and($json['protocol'])->toBe('invalid_config')
        ->and($json['reachable'])->toBeFalse();
});

it('treats unsupported backends as misconfigured and disabled as exit 2', function (): void {
    config(['attachments.scanner_backend' => 'mystery', 'cache.default' => 'array']);
    bindScannerHealthProbe(null);
    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['status'])->toBe('misconfigured');

    config(['attachments.scanner_backend' => 'disabled']);
    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(2);
    $json = json_decode(Artisan::output(), true);
    expect($json['status'])->toBe('disabled')->and($json['enabled'])->toBeFalse();

    expect(Artisan::call('attachments:scanner-health'))->toBe(2);
    $output = Artisan::output();
    expect($output)->toContain('status: disabled')
        ->and($output)->toContain('backend: disabled')
        ->and($output)->not->toContain('127.0.0.1');
});

it('maps probe exceptions to failed without unsafe details', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'cache.default' => 'array']);
    bindScannerHealthProbe(function (): array {
        throw new RuntimeException('tcp://secret-host:3310 refused');
    });

    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);
    expect($json['status'])->toBe('failed')
        ->and($json['protocol'])->toBe('error')
        ->and($output)->not->toContain('secret-host')
        ->and($output)->not->toContain('refused');
});
