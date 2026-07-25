<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundMessageTimelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{message: OutboundMessage, token: string}
 */
function outboundTimelineContext(array $overrides = []): array
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'timeline-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Timeline',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'sender',
        'full_address' => 'sender@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $message = OutboundMessage::query()->create(array_merge([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'timeline-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'bcc_recipients' => ['secret-bcc@example.test'],
        'subject' => 'Secret subject text',
        'text_body' => 'Body',
        'provider' => 'generic',
        'attempt_count' => 1,
        'sent_at' => now()->subMinute(),
    ], $overrides));
    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'timeline',
        permissions: ['outbound_messages:read'],
        user: $user,
    )->plainToken;

    return ['message' => $message, 'token' => $token, 'user' => $user];
}

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'outbound-timeline-test-secret']);
});

it('requires authentication to view a message timeline', function (): void {
    $ctx = outboundTimelineContext();

    $this->getJson('/api/v1/outbound-messages/'.$ctx['message']->getKey().'/timeline')->assertUnauthorized();
});

it('rejects a token missing the read scope', function (): void {
    $ctx = outboundTimelineContext();
    $writeOnlyToken = app(CreateApiKeyAction::class)->issue(
        userId: $ctx['user']->id,
        name: 'write-only',
        permissions: ['outbound_messages:write'],
        user: $ctx['user'],
    )->plainToken;

    $this->withToken($writeOnlyToken)
        ->getJson('/api/v1/outbound-messages/'.$ctx['message']->getKey().'/timeline')
        ->assertForbidden();
});

it('returns 404 for a message owned by another user', function (): void {
    $ctx = outboundTimelineContext();
    $other = outboundTimelineContext();

    $this->withToken($other['token'])
        ->getJson('/api/v1/outbound-messages/'.$ctx['message']->getKey().'/timeline')
        ->assertNotFound();
});

it('returns a safe user timeline with created, sent, and delivered entries', function (): void {
    $ctx = outboundTimelineContext();
    // Explicit, strictly increasing timestamps: audit_logs.created_at has
    // only whole-second precision, so same-second writes would otherwise
    // sort ambiguously.
    app(AuditLogWriter::class)->write('outbound.message_created', (string) $ctx['user']->id, $ctx['message'], null, null, null, now()->subSeconds(4));
    app(AuditLogWriter::class)->write('outbound.message_queued', (string) $ctx['user']->id, $ctx['message'], null, null, null, now()->subSeconds(3));
    app(AuditLogWriter::class)->write('outbound.message_sent', (string) $ctx['user']->id, $ctx['message'], null, null, null, now()->subSeconds(2));
    app(AuditLogWriter::class)->write('outbound.delivery_confirmed', (string) $ctx['user']->id, $ctx['message'], null, null, ['provider' => 'generic'], now()->subSecond());

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages/'.$ctx['message']->getKey().'/timeline')
        ->assertOk();

    $types = collect($response->json('data.timeline'))->pluck('type');
    expect($types->all())->toBe(['created', 'queued', 'sent', 'delivered']);

    $body = $response->getContent();
    expect($body)->not->toContain('secret-bcc@example.test')
        ->and($body)->not->toContain('outbound-timeline-test-secret');

    foreach ($response->json('data.timeline') as $entry) {
        expect($entry)->not->toHaveKey('provider')
            ->and($entry)->not->toHaveKey('failure_code')
            ->and($entry)->not->toHaveKey('audit_action');
    }
});

it('redacts bounce diagnostics and complaint metadata from the user timeline', function (): void {
    $ctx = outboundTimelineContext();
    app(AuditLogWriter::class)->write(
        'outbound.bounce_received',
        (string) $ctx['user']->id,
        $ctx['message'],
        null,
        null,
        ['provider' => 'generic', 'failure_code' => 'provider_bounce', 'event_type' => 'bounced'],
    );
    app(AuditLogWriter::class)->write(
        'outbound.complaint_received',
        (string) $ctx['user']->id,
        $ctx['message'],
        null,
        null,
        ['provider' => 'generic', 'event_type' => 'complained'],
    );

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages/'.$ctx['message']->getKey().'/timeline')
        ->assertOk();

    $entries = $response->json('data.timeline');
    $types = collect($entries)->pluck('type');

    // Bounces surface to the user as a generic failure (never "bounced");
    // complaints never surface to the user at all.
    expect($types->all())->toBe(['failed'])
        ->and($entries[0]['category'])->toBe('permanent_issue')
        ->and($response->getContent())->not->toContain('provider_bounce')
        ->and($response->getContent())->not->toContain('complain');
});

it('shows a coarser safe category to admins without exposing raw failure codes in the user view', function (): void {
    $ctx = outboundTimelineContext();
    app(AuditLogWriter::class)->write(
        'outbound.message_failed',
        (string) $ctx['user']->id,
        $ctx['message'],
        null,
        null,
        ['failure_code' => 'smtp_421_temp', 'attempt' => 1],
    );

    $userEntries = app(OutboundMessageTimelineBuilder::class)->build($ctx['message']->fresh(), admin: false);
    $adminEntries = app(OutboundMessageTimelineBuilder::class)->build($ctx['message']->fresh(), admin: true);

    expect($userEntries[0]['category'])->toBe('temporary_issue')
        ->and($userEntries[0])->not->toHaveKey('failure_code');

    expect($adminEntries[0]['failure_code'])->toBe('smtp_421_temp')
        ->and($adminEntries[0]['category'])->toBe('transport_temporary');
});

it('never includes admin-only actions such as complaints in the user-visible allow-list', function (): void {
    $ctx = outboundTimelineContext();
    app(AuditLogWriter::class)->write('outbound.complaint_received', (string) $ctx['user']->id, $ctx['message'], null, null, ['event_type' => 'complained']);
    app(AuditLogWriter::class)->write('outbound.provider_event_unmatched', null, $ctx['message'], null, null, ['event_type' => 'unknown']);

    $userEntries = app(OutboundMessageTimelineBuilder::class)->build($ctx['message']->fresh(), admin: false);
    $adminEntries = app(OutboundMessageTimelineBuilder::class)->build($ctx['message']->fresh(), admin: true);

    expect($userEntries)->toBe([])
        ->and(collect($adminEntries)->pluck('type')->all())->toBe(['complained']);
});

it('is a pure read model that never writes its own audit rows', function (): void {
    $ctx = outboundTimelineContext();
    $before = AuditLog::query()->count();

    app(OutboundMessageTimelineBuilder::class)->build($ctx['message']->fresh(), admin: true);
    app(OutboundMessageTimelineBuilder::class)->build($ctx['message']->fresh(), admin: false);

    expect(AuditLog::query()->count())->toBe($before);
});
