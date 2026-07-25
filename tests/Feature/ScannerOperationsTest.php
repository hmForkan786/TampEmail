<?php

declare(strict_types=1);

use App\Enums\AttachmentScanStatus;
use App\Filament\Admin\Pages\AttachmentScannerOps;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Inbound\AttachmentScannerHealthService;
use App\Services\Inbound\AttachmentScannerOpsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function scannerOpsAttachment(AttachmentScanStatus $status, ?Carbon $createdAt = null): Attachment
{
    $domain = Domain::query()->create([
        'domain' => 'ops-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Ops',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'local_part' => 'ops',
        'full_address' => 'ops@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $email = Email::query()->create([
        'inbox_id' => $inbox->id,
        'message_id' => 'ops-'.bin2hex(random_bytes(3)),
        'sender_email' => 'a@test',
        'recipient_email' => $inbox->full_address,
        'received_at' => now(),
        'size_bytes' => 1,
        'processing_status' => 'received',
    ]);

    $attachment = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'x.bin',
        'stored_filename' => 'x',
        'mime_type' => 'application/octet-stream',
        'size_bytes' => 1,
        'checksum_sha256' => hash('sha256', 'x'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/opaque',
        'scan_status' => $status,
        'is_safe' => $status === AttachmentScanStatus::Clean,
        'scanned_at' => in_array($status, [AttachmentScanStatus::Clean, AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true) ? now() : null,
    ]);

    if ($createdAt !== null) {
        Attachment::query()->whereKey($attachment->getKey())->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $attachment->refresh();
    }

    return $attachment;
}

it('allows authorized admins to view scanner operations data', function (): void {
    config(['attachments.scanner_backend' => 'disabled']);
    scannerOpsAttachment(AttachmentScanStatus::Pending);
    scannerOpsAttachment(AttachmentScanStatus::Clean);
    scannerOpsAttachment(AttachmentScanStatus::Infected);
    scannerOpsAttachment(AttachmentScanStatus::Failed);
    $admin = User::factory()->platformAdmin()->create();

    $response = $this->actingAs($admin)->get('/admin/attachment-scanner-ops');
    $response->assertOk()
        ->assertSee('Scanner readiness')
        ->assertSee('Quarantine overview')
        ->assertDontSee('quarantine/')
        ->assertDontSee('X5O!P%@AP', false);

    expect(AttachmentScannerOps::canAccess())->toBeTrue()
        ->and(AttachmentScannerOps::shouldRegisterNavigation())->toBeTrue();
});

it('denies unauthorized users from scanner operations', function (): void {
    foreach ([User::factory()->platformOperator()->create(), User::factory()->create()] as $actor) {
        $this->actingAs($actor)->get('/admin/attachment-scanner-ops')->assertForbidden();
        expect(AttachmentScannerOps::canAccess())->toBeFalse();
    }
});

it('reports readiness healthy degraded and failed states without automatic live-check', function (): void {
    app()->instance(AttachmentScannerHealthService::class, new AttachmentScannerHealthService(fn (): array => [
        'healthy' => true,
        'reachable' => true,
        'protocol' => 'pong',
    ]));
    config(['attachments.scanner_backend' => 'clamav']);
    expect(app(AttachmentScannerOpsService::class)->readiness()['state'])->toBe('healthy');

    app()->instance(AttachmentScannerHealthService::class, new AttachmentScannerHealthService(fn (): array => [
        'healthy' => false,
        'reachable' => false,
        'protocol' => 'unavailable',
    ]));
    expect(app(AttachmentScannerOpsService::class)->readiness()['state'])->toBe('degraded');

    config(['attachments.scanner_backend' => 'not-a-backend']);
    app()->forgetInstance(AttachmentScannerHealthService::class);
    expect(app(AttachmentScannerOpsService::class)->readiness()['state'])->toBe('failed');

    $admin = User::factory()->platformAdmin()->create();
    $this->actingAs($admin)
        ->get('/admin/attachment-scanner-ops')
        ->assertOk()
        ->assertSee('Not run on page load');
});

it('counts pending clean infected failed and excludes soft-deleted records', function (): void {
    config(['attachments.scanner_backend' => 'disabled']);
    scannerOpsAttachment(AttachmentScanStatus::Pending);
    scannerOpsAttachment(AttachmentScanStatus::Clean);
    scannerOpsAttachment(AttachmentScanStatus::Infected);
    scannerOpsAttachment(AttachmentScanStatus::Failed);
    $deleted = scannerOpsAttachment(AttachmentScanStatus::Infected);
    $deleted->delete();
    scannerOpsAttachment(AttachmentScanStatus::Pending, now()->subDays(10));

    $counts = app(AttachmentScannerOpsService::class)->scanCounts(now()->subDay());
    expect($counts['pending'])->toBe(1)
        ->and($counts['clean'])->toBe(1)
        ->and($counts['infected'])->toBe(1)
        ->and($counts['failed'])->toBe(1);

    $quarantine = app(AttachmentScannerOpsService::class)->quarantineOverview();
    expect($quarantine['infected_count'])->toBe(1)
        ->and($quarantine['failed_count'])->toBe(1)
        ->and($quarantine['awaiting_review'])->toBe(2);
});

it('calculates oldest pending age and marks degraded backlog state', function (): void {
    config([
        'attachments.scanner_backend' => 'disabled',
        'attachments.ops.pending_backlog_threshold' => 0,
        'attachments.ops.oldest_pending_seconds_threshold' => 0,
    ]);
    scannerOpsAttachment(AttachmentScanStatus::Pending, now()->subMinutes(30));

    $report = app(AttachmentScannerOpsService::class)->report();
    expect($report['queue']['oldest_pending_attachment_age_seconds'])->toBeGreaterThan(0)
        ->and($report['status'])->toBe('degraded')
        ->and($report['issues'])->toContain('pending_backlog_threshold');
});

it('authorizes and rate-limits the live-check action', function (): void {
    config([
        'attachments.scanner_backend' => 'disabled',
        'attachments.ops.live_check_per_minute' => 1,
    ]);
    $admin = User::factory()->platformAdmin()->create();
    $operator = User::factory()->platformOperator()->create();

    RateLimiter::clear('attachments-scanner-live-check:'.$admin->id);

    Livewire::actingAs($admin)
        ->test(AttachmentScannerOps::class)
        ->callAction('liveCheck')
        ->assertSuccessful()
        ->assertSee('skipped');

    Livewire::actingAs($admin)
        ->test(AttachmentScannerOps::class)
        ->callAction('liveCheck')
        ->assertSuccessful();

    Livewire::actingAs($operator)
        ->test(AttachmentScannerOps::class)
        ->assertForbidden();
});

it('exposes valid snake_case json from the aggregate status command', function (): void {
    config(['attachments.scanner_backend' => 'disabled']);
    $exit = Artisan::call('attachments:scanner-status', ['--json' => true]);
    $output = Artisan::output();
    $decoded = json_decode($output, true);

    expect($exit)->toBe(2)
        ->and($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['status', 'readiness', 'counts', 'queue', 'quarantine', 'issues'])
        ->and($decoded['counts'])->toHaveKey('last_24_hours')
        ->and($output)->not->toContain('storage_path')
        ->and($output)->not->toContain('X5O!P%@AP');
});

it('handles an empty database safely', function (): void {
    config(['attachments.scanner_backend' => 'disabled']);
    $report = app(AttachmentScannerOpsService::class)->report();
    expect($report['counts']['last_24_hours']['pending'])->toBe(0)
        ->and($report['quarantine']['awaiting_review'])->toBe(0)
        ->and($report['queue']['retry_backlog'])->toBe(0);
});
