<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\FakeOutboundTransport;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundOpsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-ops-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.transport' => 'array',
        'queue.default' => 'sync',
    ]);
});

/**
 * @return array{user: User, inbox: Inbox, token: string, transport: FakeOutboundTransport}
 */
function outboundOpsContext(): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'ops-'.uniqid(), 'name' => 'Ops', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'currency' => 'USD', 'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        ['name' => 'Send Email', 'value_type' => ValueType::Boolean, 'default_value' => ['enabled' => true], 'is_active' => true, 'display_order' => 10],
    );
    $plan->features()->syncWithoutDetaching([$feature->id => ['feature_value' => ['enabled' => true]]]);
    Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true,
        'price' => '0.00', 'currency' => 'USD',
    ]);
    $domain = Domain::query()->create([
        'domain' => 'ops-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Ops',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'outbound_enabled' => true, 'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id, 'user_id' => $user->id, 'local_part' => 'ops',
        'full_address' => 'ops@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ]);
    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id, name: 'ops', permissions: ['outbound_messages:write', 'outbound_messages:read'], user: $user,
    )->plainToken;
    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'ops-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    return compact('user', 'inbox', 'token', 'transport');
}

it('schedules retryable failures and exhausts permanently', function (): void {
    Queue::fake();
    $ctx = outboundOpsContext();
    config(['outbound.send_max_attempts' => 2]);

    $id = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', [
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'retry-1',
        'to' => ['a@example.test'],
        'subject' => 'Retry',
        'text_body' => 'body',
    ])->json('data.id');

    $ctx['transport']->setNextResult(OutboundDeliveryResult::temporaryFailure('smtp_4xx', 'Temp'));
    try {
        (new DeliverOutboundMessageJob($id))->handle(
            $ctx['transport'],
            app(OutboundAuthorizationService::class),
            app(AuditLogWriter::class),
            app(OutboundAttachmentSelector::class),
        );
    } catch (RuntimeException) {
        // expected for Laravel retry
    }

    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Queued)
        ->and(AuditLog::query()->where('action', 'outbound.retry_scheduled')->exists())->toBeTrue();

    $ctx['transport']->setNextResult(OutboundDeliveryResult::temporaryFailure('smtp_4xx', 'Temp'));
    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
    );

    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Failed)
        ->and(AuditLog::query()->where('action', 'outbound.retry_exhausted')->exists())->toBeTrue();
});

it('cancels queued messages and rejects cancel after sent', function (): void {
    Queue::fake();
    $ctx = outboundOpsContext();

    $id = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', [
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'cancel-1',
        'to' => ['a@example.test'],
        'subject' => 'Cancel me',
        'text_body' => 'body',
    ])->json('data.id');

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$id.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    expect(AuditLog::query()->where('action', 'outbound.message_cancelled')->exists())->toBeTrue();

    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
    );
    expect($ctx['transport']->sent)->toHaveCount(0);

    $sentId = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', [
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'cancel-2',
        'to' => ['a@example.test'],
        'subject' => 'Sent',
        'text_body' => 'body',
    ])->json('data.id');

    (new DeliverOutboundMessageJob($sentId))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
    );

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$sentId.'/cancel')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cancel_not_allowed');
});

it('allows manual retry for failed messages and revalidates entitlements', function (): void {
    Queue::fake();
    $ctx = outboundOpsContext();

    $id = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', [
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'manual-retry',
        'to' => ['a@example.test'],
        'subject' => 'Retry me',
        'text_body' => 'body',
    ])->json('data.id');

    $ctx['transport']->setNextResult(OutboundDeliveryResult::permanentFailure('smtp_5xx', 'Nope'));
    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
    );

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$id.'/retry')
        ->assertOk()
        ->assertJsonPath('data.state', 'queued');

    expect(AuditLog::query()->where('action', 'outbound.manual_retry_requested')->exists())->toBeTrue();

    Subscription::query()->where('user_id', $ctx['user']->id)->delete();
    OutboundMessage::query()->whereKey($id)->update([
        'state' => OutboundMessageState::Failed->value,
        'failure_code' => 'smtp_5xx',
    ]);
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$id.'/retry')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'entitlement_denied');
});

it('reports ops readiness volume and json command output without sending mail', function (): void {
    config(['outbound.enabled' => false]);
    $unknown = app(OutboundOpsService::class)->report();
    expect($unknown['status'])->toBe('unknown');

    config(['outbound.enabled' => true, 'outbound.transport' => 'unavailable']);
    $failed = app(OutboundOpsService::class)->report();
    expect($failed['status'])->toBe('failed')
        ->and($failed['readiness']['configuration_valid'])->toBeFalse();

    config(['outbound.transport' => 'array']);
    $ready = app(OutboundOpsService::class)->report();
    expect($ready['status'])->toBeIn(['healthy', 'unknown'])
        ->and($ready['readiness']['configuration_valid'])->toBeTrue()
        ->and($ready['volume']['last_24_hours'])->toHaveKeys(['queued', 'sent', 'delivered', 'failed', 'send_operations', 'replies', 'forwards']);

    $exit = Artisan::call('outbound:status', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);
    expect($exit)->toBeIn([0, 2])
        ->and($json['status'])->toBeIn(['healthy', 'unknown'])
        ->and($json)->not->toHaveKey('raw_provider_response');
});

it('exposes safe user status fields only', function (): void {
    Queue::fake();
    $ctx = outboundOpsContext();
    $id = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', [
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'status-safe',
        'to' => ['a@example.test'],
        'bcc' => ['secret@example.test'],
        'subject' => 'Status',
        'text_body' => 'body',
    ])->json('data.id');

    OutboundMessage::query()->whereKey($id)->update([
        'state' => OutboundMessageState::Failed->value,
        'failure_code' => 'smtp_5xx',
        'failure_message' => 'Safe failure',
        'metadata' => ['raw' => 'should-not-leak'],
    ]);

    $show = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$id)->assertOk();
    $show->assertJsonPath('data.failure_code', 'smtp_5xx')
        ->assertJsonMissingPath('data.metadata')
        ->assertJsonMissingPath('data.raw_provider_response');
});
