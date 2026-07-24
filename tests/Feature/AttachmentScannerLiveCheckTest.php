<?php

declare(strict_types=1);

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use App\Models\Attachment;
use App\Models\Email;
use App\Services\Inbound\AttachmentScannerLiveCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function bindLiveScanner(callable $factory): void
{
    app()->instance(
        AttachmentScannerLiveCheckService::class,
        new AttachmentScannerLiveCheckService($factory)
    );
}

function liveScannerReturning(AttachmentScanResultData ...$results): callable
{
    $queue = new ArrayObject(array_values($results));

    return function () use ($queue): AttachmentScannerInterface {
        return new class($queue) implements AttachmentScannerInterface
        {
            public function __construct(private ArrayObject $queue) {}

            public function scan(AttachmentScanRequest $request): AttachmentScanResultData
            {
                $values = $this->queue->getArrayCopy();

                if ($values === []) {
                    return new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:error');
                }

                $next = array_shift($values);
                $this->queue->exchangeArray($values);

                return $next;
            }
        };
    };
}

function assertLiveCheckOutputSafe(string $output): void
{
    expect($output)
        ->not->toContain('127.0.0.1')
        ->not->toContain('password')
        ->not->toContain('secret')
        ->not->toContain('storage/app')
        ->not->toContain('C:\\')
        ->not->toContain('/var/')
        ->not->toContain('stream_socket_client')
        ->not->toContain('Connection refused')
        ->not->toContain(AttachmentScannerLiveCheckService::EICAR);
}

it('reports healthy for clean then infected probe sequence', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav', 'platform.storage.attachments_disk' => 'attachments']);
    bindLiveScanner(liveScannerReturning(
        new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'clamav'),
        new AttachmentScanResultData(AttachmentScanResult::Infected, 'Eicar-Test', 'clamav'),
    ));

    $attachmentsBefore = Attachment::query()->count();
    $emailsBefore = Email::query()->count();
    $usersBefore = DB::table('users')->count();

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('healthy')
        ->and($json['clean_probe'])->toBe('clean')
        ->and($json['infected_probe'])->toBe('infected')
        ->and($json['issues'])->toBe([])
        ->and(Storage::disk('attachments')->allFiles())->toBe([])
        ->and(Attachment::query()->count())->toBe($attachmentsBefore)
        ->and(Email::query()->count())->toBe($emailsBefore)
        ->and(DB::table('users')->count())->toBe($usersBefore);

    assertLiveCheckOutputSafe($output);
});

it('fails when the clean probe is incorrectly infected', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav']);
    bindLiveScanner(liveScannerReturning(
        new AttachmentScanResultData(AttachmentScanResult::Infected, 'false-positive', 'clamav'),
    ));

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('failed')
        ->and($json['clean_probe'])->toBe('infected')
        ->and($json['issues'])->toContain('clean_probe_unexpected_result')
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);

    assertLiveCheckOutputSafe($output);
});

it('fails when the EICAR probe is incorrectly clean', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav']);
    bindLiveScanner(liveScannerReturning(
        new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'clamav'),
        new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'clamav'),
    ));

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(1);
    $json = json_decode(Artisan::output(), true);

    expect($json['status'])->toBe('failed')
        ->and($json['infected_probe'])->toBe('clean')
        ->and($json['issues'])->toContain('infected_probe_unexpected_result')
        ->and(Storage::disk('attachments')->allFiles())->toBe([]);
});

it('returns unavailable when the daemon cannot be reached', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav']);
    bindLiveScanner(liveScannerReturning(
        new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:unavailable'),
    ));

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(2);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('unavailable')
        ->and($json['clean_probe'])->toBe('unavailable')
        ->and($json['issues'])->toContain('clean_probe_unavailable');

    assertLiveCheckOutputSafe($output);
});

it('returns unavailable on scanner timeout', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav']);
    bindLiveScanner(liveScannerReturning(
        new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'clamav'),
        new AttachmentScanResultData(AttachmentScanResult::Failed, scannerVersion: 'clamav:timeout'),
    ));

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(2);
    $json = json_decode(Artisan::output(), true);

    expect($json['status'])->toBe('unavailable')
        ->and($json['infected_probe'])->toBe('timeout')
        ->and($json['issues'])->toContain('infected_probe_unavailable');
});

it('rejects disabled and unsupported backends as non-healthy', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'disabled']);

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(1);
    $disabled = json_decode(Artisan::output(), true);
    expect($disabled['status'])->toBe('disabled')
        ->and($disabled['clean_probe'])->toBe('skipped')
        ->and($disabled['infected_probe'])->toBe('skipped');

    config(['attachments.scanner_backend' => 'unknown-scanner']);
    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(1);
    $misconfigured = json_decode(Artisan::output(), true);
    expect($misconfigured['status'])->toBe('misconfigured')
        ->and($misconfigured['issues'])->toContain('scanner_backend_unsupported');
});

it('exposes safe human and json output with deterministic exit codes', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav']);
    bindLiveScanner(liveScannerReturning(
        new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'clamav'),
        new AttachmentScanResultData(AttachmentScanResult::Infected, 'Eicar-Test', 'clamav'),
    ));

    expect(Artisan::call('attachments:scanner-live-check'))->toBe(0);
    $human = Artisan::output();
    expect($human)->toContain('status: healthy')->and($human)->toContain('clean_probe: clean');
    assertLiveCheckOutputSafe($human);

    config(['attachments.scanner_backend' => 'disabled']);
    expect(Artisan::call('attachments:scanner-live-check'))->toBe(1);
    assertLiveCheckOutputSafe(Artisan::output());
});

it('cleans temporary probe files even when the scanner throws', function (): void {
    Storage::fake('attachments');
    config(['attachments.scanner_backend' => 'clamav']);
    bindLiveScanner(function (): AttachmentScannerInterface {
        return new class implements AttachmentScannerInterface
        {
            public function scan(AttachmentScanRequest $request): AttachmentScanResultData
            {
                throw new RuntimeException('socket error at 127.0.0.1:3310 password=secret');
            }
        };
    });

    expect(Artisan::call('attachments:scanner-live-check', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    $json = json_decode($output, true);

    expect($json['status'])->toBe('failed')
        ->and($json['issues'])->toContain('scanner_live_check_unavailable')
        ->and(Storage::disk('attachments')->allFiles())->toBe([])
        ->and(Attachment::query()->count())->toBe(0)
        ->and(Email::query()->count())->toBe(0);

    assertLiveCheckOutputSafe($output);
});
