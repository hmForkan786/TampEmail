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
use App\Models\Email;
use App\Models\EmailBody;
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
use App\Services\Outbound\OutboundDeliveryAttemptRecorder;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-reply-test-secret',
        'outbound.enabled' => true,
        'outbound.reply_enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'queue.default' => 'sync',
    ]);
});

/**
 * @return array{user: User, domain: Domain, inbox: Inbox, email: Email, token: string, transport: FakeOutboundTransport}
 */
function outboundReplyContext(array $emailOverrides = [], array $inboxOverrides = []): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'reply-'.uniqid(),
        'name' => 'Reply Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'reply_email'],
        ['name' => 'Reply Email', 'value_type' => ValueType::Boolean, 'default_value' => ['enabled' => true], 'is_active' => true, 'display_order' => 11],
    );
    $plan->features()->syncWithoutDetaching([$feature->id => ['feature_value' => ['enabled' => true]]]);
    Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true,
        'price' => '0.00', 'currency' => 'USD',
    ]);

    $domain = Domain::query()->create([
        'domain' => 'reply-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Reply',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'outbound_enabled' => true, 'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create(array_merge([
        'domain_id' => $domain->id, 'user_id' => $user->id, 'local_part' => 'box',
        'full_address' => 'box@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ], $inboxOverrides));

    $email = Email::query()->create(array_merge([
        'inbox_id' => $inbox->id,
        'message_id' => 'orig-'.bin2hex(random_bytes(4)).'@example.test',
        'sender_email' => 'sender@example.test',
        'recipient_email' => $inbox->full_address,
        'subject' => 'Original subject',
        'received_at' => now()->subHour(),
        'size_bytes' => 10,
        'processing_status' => 'stored',
        'headers' => ['References' => '<root@example.test>'],
    ], $emailOverrides));

    EmailBody::query()->create([
        'email_id' => $email->id,
        'text_body' => "Hello line\nSecond line",
        'html_body' => '<p>Hello</p>',
        'storage_driver' => 'database',
    ]);

    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'reply-key',
        permissions: ['outbound_messages:write', 'outbound_messages:read'],
        user: $user,
    )->plainToken;

    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'reply-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    return compact('user', 'domain', 'inbox', 'email', 'token', 'transport');
}

it('lets the owner reply using derived recipient and threading headers', function (): void {
    Queue::fake();
    $ctx = outboundReplyContext([
        'headers' => [
            'Reply-To' => 'Alias Name <replyto@example.test>',
            'References' => '<root@example.test>',
        ],
        'subject' => 'Hello there',
    ]);

    $response = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'reply-1',
        'text_body' => 'Thanks for writing.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.operation', 'reply')
        ->assertJsonPath('data.state', 'queued')
        ->assertJsonPath('data.to.0', 'replyto@example.test')
        ->assertJsonPath('data.subject', 'Re: Hello there')
        ->assertJsonPath('data.from.email', $ctx['inbox']->full_address);

    $message = OutboundMessage::query()->first();
    expect($message->in_reply_to)->toContain($ctx['email']->message_id)
        ->and($message->references)->toContain('<root@example.test>')
        ->and($message->text_body)->toContain('Thanks for writing.')
        ->and($message->text_body)->toContain('> Hello line');

    expect(AuditLog::query()->where('action', 'outbound.reply_created')->exists())->toBeTrue();
});

it('falls back to sender and rejects non-owners inactive and entitlement failures', function (): void {
    Queue::fake();
    $ctx = outboundReplyContext();

    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'fallback',
        'text_body' => 'Hi',
    ])->assertCreated()->assertJsonPath('data.to.0', 'sender@example.test');

    $other = User::factory()->create();
    $otherToken = app(CreateApiKeyAction::class)->issue(
        userId: $other->id, name: 'x', permissions: ['outbound_messages:write'], user: $other,
    )->plainToken;
    $this->withToken($otherToken)->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'denied', 'text_body' => 'no',
    ])->assertNotFound();

    $ctx['inbox']->update(['is_active' => false]);
    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'inactive', 'text_body' => 'no',
    ])->assertStatus(422)->assertJsonPath('error.code', 'inbox_inactive');

    $ctx['inbox']->update(['is_active' => true]);
    $ctx['domain']->update(['outbound_enabled' => false]);
    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'domain', 'text_body' => 'no',
    ])->assertForbidden()->assertJsonPath('error.code', 'domain_outbound_disabled');

    $ctx['domain']->update(['outbound_enabled' => true]);
    Subscription::query()->where('user_id', $ctx['user']->id)->delete();
    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'ent', 'text_body' => 'no',
    ])->assertForbidden()->assertJsonPath('error.code', 'entitlement_denied');
});

it('rejects deleted originals null return paths and arbitrary to overrides', function (): void {
    Queue::fake();
    $ctx = outboundReplyContext(['sender_email' => 'mailer-daemon@example.test']);

    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'daemon', 'text_body' => 'no',
    ])->assertStatus(422)->assertJsonPath('error.code', 'null_return_path');

    $ctx2 = outboundReplyContext();
    $this->withToken($ctx2['token'])->postJson('/api/v1/emails/'.$ctx2['email']->id.'/reply', [
        'idempotency_key' => 'to-block',
        'text_body' => 'Hi',
        'to' => ['evil@example.test'],
    ])->assertStatus(422);

    $ctx2['email']->delete();
    $this->withToken($ctx2['token'])->postJson('/api/v1/emails/'.$ctx2['email']->id.'/reply', [
        'idempotency_key' => 'deleted', 'text_body' => 'Hi',
    ])->assertNotFound();
});

it('supports reply idempotency and transport outcomes', function (): void {
    Queue::fake();
    $ctx = outboundReplyContext(['subject' => 'Re: Already prefixed']);

    $payload = ['idempotency_key' => 'same-reply', 'text_body' => 'Again'];
    $first = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', $payload)->assertCreated();
    $second = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', $payload)->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($first->json('data.subject'))->toBe('Re: Already prefixed');

    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'same-reply',
        'text_body' => 'Different',
    ])->assertStatus(409);

    $id = $first->json('data.id');
    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
    );
    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Sent)
        ->and(AuditLog::query()->where('action', 'outbound.reply_sent')->exists())->toBeTrue();

    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
    );
    expect($ctx['transport']->sent)->toHaveCount(1);

    $failId = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'fail-reply',
        'text_body' => 'Fail me',
    ])->json('data.id');
    $ctx['transport']->setNextResult(OutboundDeliveryResult::permanentFailure('smtp_5xx', 'Nope'));
    (new DeliverOutboundMessageJob($failId))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
    );
    expect(OutboundMessage::query()->find($failId)->state)->toBe(OutboundMessageState::Failed)
        ->and(AuditLog::query()->where('action', 'outbound.reply_failed')->exists())->toBeTrue();
});

it('skips invalid reply-to and uses sender', function (): void {
    Queue::fake();
    $ctx = outboundReplyContext([
        'headers' => ['Reply-To' => "bad\r\ninject@example.test"],
        'sender_email' => 'real@example.test',
    ]);

    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/reply', [
        'idempotency_key' => 'skip-bad-reply-to',
        'text_body' => 'Hi',
    ])->assertCreated()->assertJsonPath('data.to.0', 'real@example.test');
});
