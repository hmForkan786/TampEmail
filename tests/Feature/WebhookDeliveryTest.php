<?php

use App\Actions\Inbound\IngestInboundEmailAction;
use App\DTOs\Inbound\InboundResolution;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\Enums\InboundRoutingCode;
use App\Jobs\DeliverWebhookJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\Subscription;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\Webhook\WebhookDeliveryStateMachine;
use App\Services\Webhook\WebhookDispatchService;
use App\Services\Webhook\WebhookEndpointService;
use App\Services\Webhook\WebhookSecurityValidator;
use App\Services\Webhook\WebhookSignatureService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'webhook-delivery-secret',
        'queue.default' => 'sync',
        'webhooks.max_delivery_attempts' => 3,
    ]);
});

function runWebhookDeliveryJob(string $deliveryId): void
{
    (new DeliverWebhookJob($deliveryId))->handle(
        app(EntitlementService::class),
        app(WebhookEndpointService::class),
        app(WebhookSecurityValidator::class),
        app(WebhookSignatureService::class),
        app(WebhookDeliveryStateMachine::class),
        app(AuditLogWriter::class),
    );
}

it('creates one delivery per event and remains idempotent', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Hook',
        'url' => 'https://example.com/hook',
        'events' => ['outbound.message.sent'],
        'is_active' => true,
        'secret_encrypted' => 'delivery-secret',
    ]);

    Http::fake(['*' => Http::response('ok', 200)]);
    $dispatch = app(WebhookDispatchService::class);
    $dispatch->dispatch($user, 'outbound.message.sent', 'evt-1', ['message_id' => 'msg-1']);
    $dispatch->dispatch($user, 'outbound.message.sent', 'evt-1', ['message_id' => 'msg-1']);

    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe('delivered');
});

it('schedules retry for retryable delivery failures', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    $endpoint = WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Hook',
        'url' => 'https://example.com/hook',
        'events' => ['outbound.message.sent'],
        'is_active' => true,
        'secret_encrypted' => 'delivery-secret',
    ]);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => 'retry-1',
        'event_type' => 'outbound.message.sent',
        'status' => 'queued',
        'payload' => ['schema_version' => '2026-07-27', 'event_id' => 'retry-1', 'event_type' => 'outbound.message.sent', 'data' => []],
    ]);
    Queue::fake();
    Http::fake(['*' => Http::response('busy', 503)]);
    runWebhookDeliveryJob((string) $delivery->id);
    expect($delivery->fresh()->status)->toBe('retry_scheduled');
    Queue::assertPushed(DeliverWebhookJob::class);
});

it('marks terminal delivery failures as failed', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    $endpoint = WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Hook',
        'url' => 'https://example.com/hook',
        'events' => ['outbound.message.sent'],
        'is_active' => true,
        'secret_encrypted' => 'delivery-secret',
    ]);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => 'terminal-1',
        'event_type' => 'outbound.message.sent',
        'status' => 'queued',
        'payload' => ['schema_version' => '2026-07-27', 'event_id' => 'terminal-1', 'event_type' => 'outbound.message.sent', 'data' => []],
    ]);
    Http::fake(['*' => Http::response('nope', 404)]);
    runWebhookDeliveryJob((string) $delivery->id);
    expect($delivery->fresh()->status)->toBe('failed');
});

it('cancels deliveries when entitlement is revoked and performs zero HTTP', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    $endpoint = WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Hook',
        'url' => 'https://example.com/hook',
        'events' => ['outbound.message.sent'],
        'is_active' => true,
        'secret_encrypted' => 'delivery-secret',
    ]);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => 'cancel-1',
        'event_type' => 'outbound.message.sent',
        'status' => 'queued',
        'payload' => ['schema_version' => '2026-07-27', 'event_id' => 'cancel-1', 'event_type' => 'outbound.message.sent', 'data' => []],
    ]);
    $plan = Subscription::query()->where('user_id', $user->id)->firstOrFail()->plan;
    $feature = Feature::query()->where('key', 'webhook.access')->sole();
    $plan->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => false]]);

    Http::fake();
    runWebhookDeliveryJob((string) $delivery->id);

    expect($delivery->fresh()->status)->toBe('cancelled');
    Http::assertNothingSent();
    expect(AuditLog::query()->where('action', 'commercial.webhook_delivery_cancelled')->exists())->toBeTrue();
});

it('scopes delivery history to the endpoint owner', function (): void {
    ['token' => $token] = premiumWebhookFixture();
    $create = $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload())->assertCreated();
    $endpointId = $create->json('data.id');
    $endpoint = WebhookEndpoint::query()->findOrFail($endpointId);
    WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => 'hist-1',
        'event_type' => 'outbound.message.sent',
        'status' => 'delivered',
        'payload' => ['schema_version' => '2026-07-27', 'event_id' => 'hist-1', 'event_type' => 'outbound.message.sent', 'data' => []],
        'delivered_at' => now(),
    ]);

    $this->withToken($token)->getJson("/api/v1/webhooks/{$endpointId}/deliveries")->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonMissing(['secret' => true]);
});

it('invalidates signatures after secret rotation', function (): void {
    $secret = 'rotation-secret';
    $body = '{"ok":true}';
    $timestamp = 1_700_000_000;
    $signer = app(WebhookSignatureService::class);
    $old = $signer->sign($secret, $timestamp, $body);
    $newSecret = 'replacement-secret';
    expect($signer->verify($newSecret, $timestamp, $body, $old))->toBeFalse()
        ->and($signer->verify($secret, $timestamp, $body, $old))->toBeTrue();
});

it('does not retry delivered deliveries', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    $endpoint = WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Hook',
        'url' => 'https://example.com/hook',
        'events' => ['outbound.message.sent'],
        'is_active' => true,
        'secret_encrypted' => 'delivery-secret',
    ]);
    $delivery = WebhookDelivery::query()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_id' => 'done-1',
        'event_type' => 'outbound.message.sent',
        'status' => 'delivered',
        'attempt_count' => 1,
        'payload' => ['schema_version' => '2026-07-27', 'event_id' => 'done-1', 'event_type' => 'outbound.message.sent', 'data' => []],
        'delivered_at' => now(),
    ]);
    Http::fake();
    Queue::fake();
    DeliverWebhookJob::dispatch((string) $delivery->id);
    Http::assertNothingSent();
});

it('fans out inbox.email.received after inbound ingestion for owned inboxes', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    $domain = Domain::query()->create([
        'domain' => 'inbound-hook-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Inbound hook',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'owned',
        'full_address' => 'owned@'.$domain->domain,
        'inbox_type' => 'private',
        'is_active' => true,
    ]);
    WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Inbound hook',
        'url' => 'https://example.com/inbound-hook',
        'events' => ['inbox.email.received'],
        'is_active' => true,
        'secret_encrypted' => 'inbound-delivery-secret',
    ]);
    Http::fake(['*' => Http::response('ok', 200)]);

    $messageId = 'inbound-hook-'.bin2hex(random_bytes(4));
    $parsed = new ParsedInboundEmail(
        $messageId,
        'sender@example.test',
        $inbox->full_address,
        'Inbound subject',
        Carbon::now(),
        [],
        'text',
        '<p>text</p>',
        [],
        50,
    );
    $resolution = new InboundResolution(
        InboundRoutingCode::Resolved,
        $inbox->full_address,
        (string) $domain->id,
        (string) $inbox->id,
        (string) $user->id,
        false,
    );

    $email = app(IngestInboundEmailAction::class)->execute($parsed, $resolution);

    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->firstOrFail()->event_type)->toBe('inbox.email.received')
        ->and(WebhookDelivery::query()->firstOrFail()->event_id)->toBe((string) $email->getKey())
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe('delivered');

    // Duplicate ingestion must not re-dispatch.
    app(IngestInboundEmailAction::class)->execute($parsed, $resolution);
    expect(WebhookDelivery::query()->count())->toBe(1);
});

it('does not fan out inbox.email.received for anonymous inboxes', function (): void {
    ['user' => $user] = premiumWebhookFixture();
    WebhookEndpoint::query()->create([
        'user_id' => $user->id,
        'name' => 'Inbound hook',
        'url' => 'https://example.com/inbound-hook',
        'events' => ['inbox.email.received'],
        'is_active' => true,
        'secret_encrypted' => 'inbound-delivery-secret',
    ]);
    $domain = Domain::query()->create([
        'domain' => 'anon-hook-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Anonymous hook',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'local_part' => 'anon',
        'full_address' => 'anon@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $parsed = new ParsedInboundEmail(
        'anon-hook-'.bin2hex(random_bytes(4)),
        'sender@example.test',
        $inbox->full_address,
        'Anonymous subject',
        Carbon::now(),
        [],
        'text',
        'text',
        [],
        20,
    );
    $resolution = new InboundResolution(
        InboundRoutingCode::Resolved,
        $inbox->full_address,
        (string) $domain->id,
        (string) $inbox->id,
        null,
        true,
    );

    app(IngestInboundEmailAction::class)->execute($parsed, $resolution);

    expect(WebhookDelivery::query()->count())->toBe(0);
});
