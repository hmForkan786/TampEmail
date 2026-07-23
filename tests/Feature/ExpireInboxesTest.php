<?php

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Services\Inbox\ExpireInboxesService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function expirableInbox(array $overrides = []): Inbox
{
    $domain = Domain::create([
        'domain' => 'expire-'.uniqid().'.test', 'display_name' => 'Expire test',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true,
        'is_healthy' => true, 'priority' => 1, 'retention_hours' => 24,
    ]);
    return Inbox::create(array_merge([
        'domain_id' => $domain->id, 'local_part' => 'box-'.uniqid(),
        'full_address' => uniqid().'@'.$domain->domain, 'inbox_type' => 'temporary',
        'expires_at' => now()->subMinute(), 'is_active' => true,
    ], $overrides));
}

it('dry-runs without mutation or audit', function (): void {
    $inbox = expirableInbox();
    $result = app(ExpireInboxesService::class)->process();

    expect($result['eligible'])->toBe(1)->and($result['processed'])->toBe(0)->and($result['blocked_reason'])->toBe('confirmation_required');
    expect($inbox->fresh()->is_active)->toBeTrue();
    expect(AuditLog::query()->where('action', 'inbox.expired')->count())->toBe(0);
});

it('confirms eligible inboxes exactly once with safe audit payload', function (): void {
    $inbox = expirableInbox(['metadata' => ['secret' => 'hidden']]);
    $result = app(ExpireInboxesService::class)->process(true, 1);

    expect($result['processed'])->toBe(1)->and($result['batches'])->toBe(1);
    expect(Inbox::withTrashed()->find($inbox->id)->is_active)->toBeFalse();
    expect(Inbox::withTrashed()->find($inbox->id)->deleted_at)->not->toBeNull();
    $audit = AuditLog::query()->where('action', 'inbox.expired')->sole();
    expect($audit->old_values)->toBe(['is_active' => true])
        ->and($audit->new_values)->toBe(['is_active' => false])
        ->and($audit->metadata)->not->toHaveKey('address')
        ->and($audit->metadata)->not->toHaveKey('mail_server_id')
        ->and($audit->metadata)->not->toHaveKey('secret');
    expect(app(ExpireInboxesService::class)->process(true)['processed'])->toBe(0);
});

it('skips future, permanent, inactive and deleted inboxes and processes batches', function (): void {
    expirableInbox(); expirableInbox();
    expirableInbox(['expires_at' => now()->addHour()]);
    expirableInbox(['expires_at' => null]);
    expirableInbox(['is_active' => false]);
    $deleted = expirableInbox(); $deleted->delete();

    $result = app(ExpireInboxesService::class)->process(true, 1);
    expect($result['processed'])->toBe(2)->and($result['batches'])->toBe(2)
        ->and(AuditLog::query()->where('action', 'inbox.expired')->count())->toBe(2);
});

it('preserves child records while hiding expired inboxes', function (): void {
    $inbox = expirableInbox(['expires_at' => now()]);
    $result = app(ExpireInboxesService::class)->process(true);
    expect($result['failed'])->toBe(0)
        ->and(Inbox::query()->whereKey($inbox->id)->exists())->toBeFalse()
        ->and(Inbox::withTrashed()->whereKey($inbox->id)->exists())->toBeTrue();
});

it('rolls back the affected inbox when audit writing fails', function (): void {
    $inbox = expirableInbox();
    $audit = Mockery::mock(\App\Services\Audit\AuditLogWriter::class);
    $audit->shouldReceive('write')->once()->andThrow(new RuntimeException('audit unavailable'));
    app()->instance(\App\Services\Audit\AuditLogWriter::class, $audit);

    $result = app(ExpireInboxesService::class)->process(true);
    expect($result['failed'])->toBe(1)->and($result['processed'])->toBe(0);
    expect($inbox->fresh()->is_active)->toBeTrue()->and($inbox->fresh()->deleted_at)->toBeNull();
});
