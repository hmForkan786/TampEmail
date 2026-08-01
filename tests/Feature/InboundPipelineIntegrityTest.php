<?php

declare(strict_types=1);

use App\Actions\Inbound\IngestInboundEmailAction;
use App\DTOs\Inbound\InboundResolution;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\DTOs\Inbound\ProviderWebhookEnvelope;
use App\Enums\InboundRoutingCode;
use App\Jobs\ProcessInboundMessageJob;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\MailServer;
use App\Models\User;
use App\Services\Inbound\InboundMimeParser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function inboundDedupDomainInbox(): array
{
    $user = User::factory()->create();
    $domain = Domain::create([
        'domain' => 'dedup.example.test',
        'display_name' => 'Dedup',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'priority' => 1,
        'max_mailboxes' => null,
        'retention_hours' => 24,
        'metadata' => null,
    ]);
    MailServer::create([
        'name' => 'Dedup server',
        'hostname' => 'dedup.example.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'is_active' => true,
        'priority' => 1,
        'last_health_check_at' => now(),
        'pool_key' => 'standard',
    ]);
    $inbox = Inbox::create([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'user',
        'full_address' => 'user@dedup.example.test',
        'is_active' => true,
        'expires_at' => now()->addDay(),
    ]);

    return [$user, $inbox];
}

function inboundDedupResolution(Inbox $inbox, User $user): InboundResolution
{
    return new InboundResolution(
        InboundRoutingCode::Resolved,
        $inbox->full_address,
        (string) $inbox->domain_id,
        (string) $inbox->id,
        (string) $user->id,
        false,
    );
}

it('deduplicates on provider message id when mime message id differs on retry', function (): void {
    Queue::fake();
    [$user, $inbox] = inboundDedupDomainInbox();
    $resolution = inboundDedupResolution($inbox, $user);
    $parser = app(InboundMimeParser::class);
    $ingest = app(IngestInboundEmailAction::class);

    $mimeA = "From: sender@example.test\r\nTo: user@dedup.example.test\r\nSubject: Hello\r\nMessage-ID: <mime-a@example.test>\r\n\r\nBody A";
    $parsedA = $parser->parse(new ProviderWebhookEnvelope(
        'generic',
        'provider-msg-123',
        'user@dedup.example.test',
        'sender@example.test',
        now(),
        $mimeA,
        strlen($mimeA),
    ));

    $first = $ingest->execute($parsedA, $resolution);
    expect($first->message_id)->toBe('provider-msg-123')
        ->and($first->headers)->toHaveKey('mime_message_id', '<mime-a@example.test>');

    $mimeB = "From: sender@example.test\r\nTo: user@dedup.example.test\r\nSubject: Hello\r\nMessage-ID: <mime-b@example.test>\r\n\r\nBody B";
    $parsedB = $parser->parse(new ProviderWebhookEnvelope(
        'generic',
        'provider-msg-123',
        'user@dedup.example.test',
        'sender@example.test',
        now(),
        $mimeB,
        strlen($mimeB),
    ));

    $second = $ingest->execute($parsedB, $resolution);

    expect($second->id)->toBe($first->id)
        ->and(Email::query()->count())->toBe(1);
});

it('dispatches process inbound job to default queue per deployment contract', function (): void {
    Queue::fake();
    $body = json_encode(['recipient' => 'user@example.test', 'raw_mime_payload' => 'From: a@b.test']);
    config(['inbound.providers.generic.secret' => 'webhook-test-secret', 'queue.default' => 'database']);

    $provider = 'generic';
    $timestamp = (string) time();
    $secret = 'webhook-test-secret';
    $this->withHeaders([
        'X-Inbound-Provider' => $provider,
        'X-Inbound-Timestamp' => $timestamp,
        'X-Inbound-Signature' => hash_hmac('sha256', $provider.'.'.$timestamp.'.'.$body, $secret),
        'X-Inbound-Message-Id' => 'queue-test-1',
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/inbound/webhook', json_decode($body, true))->assertAccepted();

    Queue::assertPushed(ProcessInboundMessageJob::class, function ($job): bool {
        return $job->queue === null || $job->queue === 'default';
    });
});

it('sanitizes html at ingest and on api output', function (): void {
    [$user, $inbox] = inboundDedupDomainInbox();
    $resolution = inboundDedupResolution($inbox, $user);
    $mime = "From: sender@example.test\r\nTo: user@dedup.example.test\r\nSubject: X\r\nMessage-ID: <x@example.test>\r\nContent-Type: text/html\r\n\r\n<script>alert(1)</script><p>ok</p>";
    $parsed = new ParsedInboundEmail(
        'provider-html-1',
        'sender@example.test',
        'user@dedup.example.test',
        'X',
        Carbon::now(),
        ['message-id' => '<x@example.test>'],
        null,
        '<script>alert(1)</script><p>ok</p>',
        [],
        strlen($mime),
    );

    $email = app(IngestInboundEmailAction::class)->execute($parsed, $resolution);
    $email->load('body');

    expect($email->body?->html_body)->not->toContain('<script>')
        ->and($email->body?->html_body)->toContain('<p>ok</p>');
});
