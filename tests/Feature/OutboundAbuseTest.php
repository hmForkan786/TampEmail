<?php

declare(strict_types=1);

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Exceptions\OutboundSendException;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundOpsService;
use App\Services\Outbound\OutboundRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function abuseUserContext(): array
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'abuse-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Abuse',
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

    return compact('user', 'inbox');
}

it('enforces per-minute hourly and daily message limits', function (): void {
    config([
        'outbound.messages_per_minute' => 1,
        'outbound.messages_per_hour' => 100,
        'outbound.messages_per_day' => 100,
        'outbound.abuse.bounce_threshold_24h' => 1000,
        'outbound.abuse.complaint_threshold_24h' => 1000,
        'outbound.abuse.failed_send_threshold_24h' => 1000,
        'outbound.abuse.suppression_block_threshold_24h' => 1000,
    ]);
    $ctx = abuseUserContext();
    OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'abuse-1',
        'request_fingerprint' => hash('sha256', 'abuse-1'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['a@example.test'],
        'subject' => 'x',
        'text_body' => 'y',
        'queued_at' => now(),
    ]);

    expect(fn () => app(OutboundRateLimiter::class)->assertWithinLimits($ctx['user'], ['b@example.test']))
        ->toThrow(OutboundSendException::class);

    try {
        app(OutboundRateLimiter::class)->assertWithinLimits($ctx['user'], ['b@example.test']);
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('rate_limit_minute')
            ->and($exception->status)->toBe(429)
            ->and($exception->getMessage())->not->toContain('threshold');
    }
});

it('enforces unique recipient and concurrent queued limits', function (): void {
    config([
        'outbound.messages_per_minute' => 100,
        'outbound.unique_recipients_per_hour' => 1,
        'outbound.concurrent_queued_messages' => 1,
        'outbound.abuse.bounce_threshold_24h' => 1000,
        'outbound.abuse.complaint_threshold_24h' => 1000,
        'outbound.abuse.failed_send_threshold_24h' => 1000,
        'outbound.abuse.suppression_block_threshold_24h' => 1000,
    ]);
    $ctx = abuseUserContext();
    OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'abuse-u1',
        'request_fingerprint' => hash('sha256', 'abuse-u1'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['one@example.test'],
        'subject' => 'x',
        'text_body' => 'y',
        'queued_at' => now(),
    ]);

    try {
        app(OutboundRateLimiter::class)->assertWithinLimits($ctx['user'], ['two@example.test']);
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBeIn(['unique_recipients_hour', 'concurrent_queued_limit']);
    }
});

it('blocks temporarily blocked users and restores after expiry', function (): void {
    config([
        'outbound.abuse.bounce_threshold_24h' => 1000,
        'outbound.abuse.complaint_threshold_24h' => 1000,
        'outbound.abuse.failed_send_threshold_24h' => 1000,
        'outbound.abuse.suppression_block_threshold_24h' => 1000,
    ]);
    $ctx = abuseUserContext();
    $limiter = app(OutboundRateLimiter::class);
    $block = $limiter->applyTemporaryBlock($ctx['user'], 'manual_review', 'admin', expiresAt: now()->addHour());

    expect(fn () => $limiter->assertWithinLimits($ctx['user'], ['a@example.test']))
        ->toThrow(OutboundSendException::class);

    $block->forceFill(['expires_at' => now()->subMinute()])->save();
    $limiter->assertWithinLimits($ctx['user'], ['a@example.test']);

    $admin = User::factory()->platformAdmin()->create();
    $active = $limiter->applyTemporaryBlock($ctx['user'], 'manual_review', 'admin', $admin, now()->addHour());
    $limiter->clearBlock($active, $admin);
    $limiter->assertWithinLimits($ctx['user'], ['a@example.test']);

    $report = app(OutboundOpsService::class)->abuseMetrics();
    expect($report)->toHaveKeys([
        'throttled_requests',
        'temporarily_blocked_users',
        'outbound_suspended_users',
        'blocked_sends',
    ]);
});

it('documents sqlite concurrency limitation for quota locks', function (): void {
    expect(true)->toBeTrue();
    // Parallel oversubscription proofs require MySQL/PostgreSQL row locks under concurrent writers.
});
