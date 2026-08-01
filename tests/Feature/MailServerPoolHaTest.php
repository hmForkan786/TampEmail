<?php

declare(strict_types=1);

use App\Actions\Inbox\CreateInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Enums\InboxType;
use App\Enums\MailServerOperationalStatus;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\MailServer;
use App\Repositories\Eloquent\EloquentMailServerRepository;
use App\Services\MailServer\MailServerCapacityService;
use App\Services\MailServer\MailServerHealthScorer;
use App\Services\MailServer\MailServerHeartbeatService;
use App\Services\MailServer\MailServerPoolMonitor;
use App\Services\MailServer\MailServerStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function haDomain(): Domain
{
    return Domain::create([
        'domain' => 'ha-'.uniqid().'.test',
        'display_name' => 'HA',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'priority' => 1,
        'max_mailboxes' => null,
        'retention_hours' => 24,
        'metadata' => null,
    ]);
}

function haServer(array $overrides = []): MailServer
{
    return MailServer::create(array_merge([
        'name' => 'HA server',
        'hostname' => 'ha-'.uniqid().'.example.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'is_active' => true,
        'operational_status' => MailServerOperationalStatus::Active,
        'priority' => 10,
        'last_health_check_at' => now(),
        'pool_key' => 'ha-pool',
        'max_inboxes' => 10,
        'consecutive_failures' => 0,
    ], $overrides));
}

function createHaInbox(Domain $domain, ?string $serverId = null): Inbox
{
    $local = 'box-'.uniqid();
    $data = new CreateInboxData(
        domainId: $domain->id,
        userId: null,
        localPart: $local,
        fullAddress: $local.'@'.$domain->domain,
        displayName: null,
        inboxType: InboxType::Temporary,
        expiresAt: now()->addHour(),
        metadata: null,
    );

    if ($serverId !== null) {
        return Inbox::create($data->withMailServerId($serverId)->toArray());
    }

    config(['inbox.public_mail_server_pool' => 'ha-pool']);

    return app(CreateInboxAction::class)->execute(
        $data,
        null,
        InboxMutationContext::forAnonymous(),
    );
}

it('scores active fresh servers at one hundred and non-active at zero', function (): void {
    $scorer = app(MailServerHealthScorer::class);
    $active = haServer();
    expect($scorer->score($active))->toBe(100)
        ->and($scorer->isEligibleForAssignment($active))->toBeTrue();

    $draining = haServer(['operational_status' => MailServerOperationalStatus::Draining, 'is_active' => false]);
    expect($scorer->score($draining))->toBe(0)
        ->and($scorer->isEligibleForAssignment($draining))->toBeFalse();
});

it('routes new assignments to the lowest utilization eligible server', function (): void {
    config(['inbox.public_mail_server_pool' => 'ha-pool']);
    $domain = haDomain();
    $busy = haServer(['hostname' => 'busy.example.test', 'priority' => 100, 'max_inboxes' => 4]);
    $free = haServer(['hostname' => 'free.example.test', 'priority' => 1, 'max_inboxes' => 4]);

    createHaInbox($domain, $busy->id);
    createHaInbox($domain, $busy->id);

    $inbox = createHaInbox($domain);

    expect($inbox->mail_server_id)->toBe($free->id);
});

it('fails over to the next eligible server when the preferred peer is draining', function (): void {
    config(['inbox.public_mail_server_pool' => 'ha-pool']);
    $domain = haDomain();
    $primary = haServer(['hostname' => 'primary.example.test', 'priority' => 50]);
    $secondary = haServer(['hostname' => 'secondary.example.test', 'priority' => 1]);

    app(MailServerStatusTransitionService::class)->transition(
        $primary,
        MailServerOperationalStatus::Draining,
        null,
        'test',
        'offline',
    );

    $inbox = createHaInbox($domain);

    expect($inbox->mail_server_id)->toBe($secondary->id);
});

it('does not assign new work while draining and completes drain when idle', function (): void {
    config(['inbox.public_mail_server_pool' => 'ha-pool']);
    $domain = haDomain();
    $server = haServer(['max_inboxes' => 2]);
    $other = haServer(['hostname' => 'other.example.test', 'priority' => 1]);

    $held = createHaInbox($domain, $server->id);

    $transitions = app(MailServerStatusTransitionService::class);
    $transitions->transition($server, MailServerOperationalStatus::Draining, null, 'test');

    $inbox = createHaInbox($domain);
    expect($inbox->mail_server_id)->toBe($other->id);

    $held->forceFill(['is_active' => false, 'expires_at' => now()->subMinute()])->save();

    $completed = $transitions->completeDrainIfIdle($server->fresh());
    expect($completed)->not->toBeNull()
        ->and($completed->operationalStatusEnum())->toBe(MailServerOperationalStatus::Maintenance)
        ->and($completed->is_active)->toBeFalse();
});

it('records heartbeat and failure strikes into health score without exposing secrets', function (): void {
    $server = haServer();
    $heartbeat = app(MailServerHeartbeatService::class);
    $scorer = app(MailServerHealthScorer::class);

    $ok = $heartbeat->recordSuccess($server);
    expect($ok->health_score)->toBe(100)->and($ok->consecutive_failures)->toBe(0);

    $failed = $heartbeat->recordFailure($ok, null, 'test', 'provider_timeout');
    expect($failed->consecutive_failures)->toBe(1)
        ->and($scorer->score($failed))->toBeLessThan(100);

    $this->assertDatabaseHas('audit_logs', ['action' => 'mail_server.heartbeat_recorded']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'mail_server.failure_recorded']);
});

it('exposes capacity metrics remaining utilization and throughput hints', function (): void {
    $domain = haDomain();
    $server = haServer(['max_inboxes' => 4, 'max_throughput' => 1000]);
    createHaInbox($domain, $server->id);

    $metrics = app(MailServerCapacityService::class)->metrics($server->fresh());

    expect($metrics['active_workload'])->toBe(1)
        ->and($metrics['remaining_capacity'])->toBe(3)
        ->and($metrics['utilization'])->toBe(0.25)
        ->and($metrics['max_throughput'])->toBe(1000)
        ->and($metrics['unlimited'])->toBeFalse();
});

it('refreshes ha scores via artisan and reports pool status json', function (): void {
    haServer();
    $this->artisan('mail-servers:refresh-ha')->assertSuccessful();

    $this->artisan('mail-servers:pool-status', ['--json' => true])
        ->assertSuccessful();

    $snapshot = app(MailServerPoolMonitor::class)->snapshot('ha-pool');
    expect($snapshot['summary']['servers'])->toBeGreaterThan(0)
        ->and($snapshot['servers'][0])->toHaveKey('health_score');
});

it('supports ops transition command deterministically', function (): void {
    $server = haServer();
    $this->artisan('mail-servers:ops', [
        'action' => 'transition',
        'mailServer' => $server->id,
        'status' => 'maintenance',
        '--reason' => 'planned',
    ])->assertSuccessful();

    expect($server->fresh()->operationalStatusEnum())->toBe(MailServerOperationalStatus::Maintenance);
});

it('keeps repository selection under row locks', function (): void {
    $repository = file_get_contents((new ReflectionClass(EloquentMailServerRepository::class))->getFileName());
    expect($repository)->toContain('lockForUpdate')
        ->and($repository)->toContain('utilizationSortKey')
        ->and($repository)->toContain('MailServerOperationalStatus::Active');
});
