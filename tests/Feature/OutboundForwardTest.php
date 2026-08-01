<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\AttachmentScanStatus;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Attachment;
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
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-forward-test-secret',
        'outbound.enabled' => true,
        'outbound.forward_enabled' => true,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'filesystems.disks.attachments.visibility' => 'private',
        'queue.default' => 'sync',
    ]);
    Storage::fake('attachments');
});

/**
 * @return array{user: User, domain: Domain, inbox: Inbox, email: Email, token: string, transport: FakeOutboundTransport}
 */
function outboundForwardContext(): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'fwd-'.uniqid(), 'name' => 'Forward Plan', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'currency' => 'USD', 'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'forward_email'],
        ['name' => 'Forward Email', 'value_type' => ValueType::Boolean, 'default_value' => ['enabled' => true], 'is_active' => true, 'display_order' => 12],
    );
    $plan->features()->syncWithoutDetaching([$feature->id => ['feature_value' => ['enabled' => true]]]);
    $sendFeature = Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        ['name' => 'Send Email', 'value_type' => ValueType::Boolean, 'is_active' => true, 'display_order' => 10],
    );
    $plan->features()->syncWithoutDetaching([$sendFeature->id => ['feature_value' => ['enabled' => true]]]);
    $messageLimit = Feature::query()->firstOrCreate(['key' => 'outbound_messages_per_period'], ['name' => 'Outbound messages', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 13]);
    $plan->features()->syncWithoutDetaching([$messageLimit->id => ['feature_value' => ['limit' => 1000, 'reset_period' => 'monthly']]]);
    attachApiCommercialFeatures($plan);
    Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true,
        'price' => '0.00', 'currency' => 'USD',
    ]);

    $domain = Domain::query()->create([
        'domain' => 'fwd-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Fwd',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'outbound_enabled' => true, 'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id, 'user_id' => $user->id, 'local_part' => 'fwd',
        'full_address' => 'fwd@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ]);
    $email = Email::query()->create([
        'inbox_id' => $inbox->id, 'message_id' => 'fwd-'.bin2hex(random_bytes(3)),
        'sender_email' => 'from@example.test', 'recipient_email' => $inbox->full_address,
        'subject' => 'Please forward', 'received_at' => now()->subHour(), 'size_bytes' => 20,
        'processing_status' => 'stored', 'headers' => ['Bcc' => 'secret@example.test'],
    ]);
    EmailBody::query()->create([
        'email_id' => $email->id, 'text_body' => 'Original body', 'html_body' => '<p>Original</p><script>x</script>',
        'storage_driver' => 'database',
    ]);

    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id, name: 'fwd-key', permissions: ['outbound_messages:write'], user: $user,
    )->plainToken;

    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'fwd-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    return compact('user', 'domain', 'inbox', 'email', 'token', 'transport');
}

function makeAttachment(Email $email, AttachmentScanStatus $status, bool $safe, string $path = 'ok.txt', bool $store = true): Attachment
{
    $storagePath = 'quarantine/'.$email->id.'/'.bin2hex(random_bytes(4));
    if ($store) {
        Storage::disk('attachments')->put($storagePath, 'clean-bytes');
    }

    return Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => $path,
        'stored_filename' => basename($storagePath),
        'mime_type' => 'text/plain',
        'size_bytes' => 10,
        'checksum_sha256' => hash('sha256', 'clean-bytes'),
        'storage_disk' => 'attachments',
        'storage_path' => $storagePath,
        'scan_status' => $status,
        'is_safe' => $safe,
    ]);
}

it('forwards with sanitized context and clean attachments', function (): void {
    Queue::fake();
    $ctx = outboundForwardContext();
    $clean = makeAttachment($ctx['email'], AttachmentScanStatus::Clean, true, 'report.pdf');

    $response = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', [
        'idempotency_key' => 'fwd-1',
        'to' => ['friend@example.test'],
        'bcc' => ['blind@example.test'],
        'text_body' => 'FYI',
        'attachment_ids' => [$clean->id],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.operation', 'forward')
        ->assertJsonPath('data.subject', 'Fwd: Please forward')
        ->assertJsonPath('data.to.0', 'friend@example.test')
        ->assertJsonPath('data.from.email', $ctx['inbox']->full_address);

    $message = OutboundMessage::query()->first();
    expect($message->text_body)->toContain('FYI')
        ->and($message->text_body)->toContain('---------- Forwarded message ----------')
        ->and($message->text_body)->toContain('from@example.test')
        ->and($message->text_body)->not->toContain('secret@example.test')
        ->and($message->attachment_ids)->toBe([$clean->id]);

    expect(AuditLog::query()->where('action', 'outbound.forward_created')->exists())->toBeTrue();

    (new DeliverOutboundMessageJob($message->id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
    );

    expect($message->fresh()->state)->toBe(OutboundMessageState::Sent)
        ->and($ctx['transport']->sent[0]->attachments)->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'outbound.forward_sent')->exists())->toBeTrue();
});

it('rejects unsafe missing and cross-email attachments all-or-nothing', function (): void {
    Queue::fake();
    $ctx = outboundForwardContext();
    $pending = makeAttachment($ctx['email'], AttachmentScanStatus::Pending, false);
    $infected = makeAttachment($ctx['email'], AttachmentScanStatus::Infected, false);
    $failed = makeAttachment($ctx['email'], AttachmentScanStatus::Failed, false);
    $skipped = makeAttachment($ctx['email'], AttachmentScanStatus::Skipped, false);
    $missing = makeAttachment($ctx['email'], AttachmentScanStatus::Clean, true, 'missing.txt', store: false);
    $otherEmail = Email::query()->create([
        'inbox_id' => $ctx['inbox']->id, 'message_id' => 'other-'.bin2hex(random_bytes(3)),
        'sender_email' => 'x@example.test', 'recipient_email' => $ctx['inbox']->full_address,
        'subject' => 'Other', 'received_at' => now(), 'size_bytes' => 1, 'processing_status' => 'stored',
    ]);
    $foreign = makeAttachment($otherEmail, AttachmentScanStatus::Clean, true);

    foreach ([
        [[$pending->id], 'attachment_unsafe'],
        [[$infected->id], 'attachment_unsafe'],
        [[$failed->id], 'attachment_unsafe'],
        [[$skipped->id], 'attachment_unsafe'],
        [[$missing->id], 'attachment_unsafe'],
        [[$foreign->id], 'attachment_not_found'],
    ] as [$ids, $code]) {
        $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', [
            'idempotency_key' => 'bad-'.bin2hex(random_bytes(2)),
            'to' => ['a@example.test'],
            'text_body' => 'x',
            'attachment_ids' => $ids,
        ])->assertStatus(422)->assertJsonPath('error.code', $code);
    }

    expect(OutboundMessage::query()->count())->toBe(0);
});

it('rechecks attachment safety in the queue job', function (): void {
    Queue::fake();
    $ctx = outboundForwardContext();
    $clean = makeAttachment($ctx['email'], AttachmentScanStatus::Clean, true);

    $id = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', [
        'idempotency_key' => 'recheck',
        'to' => ['a@example.test'],
        'text_body' => 'x',
        'attachment_ids' => [$clean->id],
    ])->json('data.id');

    $clean->update(['scan_status' => AttachmentScanStatus::Infected, 'is_safe' => false]);

    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
    );

    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Failed)
        ->and($ctx['transport']->sent)->toHaveCount(0)
        ->and(AuditLog::query()->where('action', 'outbound.forward_failed')->exists())->toBeTrue();
});

it('enforces forward authorization and idempotency', function (): void {
    Queue::fake();
    $ctx = outboundForwardContext();
    $payload = [
        'idempotency_key' => 'same-fwd',
        'to' => ['a@example.test'],
        'text_body' => 'hello',
    ];

    $first = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', $payload)->assertCreated();
    $second = $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', $payload)->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));

    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', array_merge($payload, [
        'to' => ['b@example.test'],
    ]))->assertStatus(409);

    $other = commercialApiUser();
    $otherToken = $other['token'];
    $this->withToken($otherToken)->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', [
        'idempotency_key' => 'x', 'to' => ['a@example.test'], 'text_body' => 'no',
    ])->assertNotFound();

    $this->withToken($ctx['token'])->postJson('/api/v1/emails/'.$ctx['email']->id.'/forward', [
        'idempotency_key' => 'from-block',
        'to' => ['a@example.test'],
        'text_body' => 'no',
        'from' => 'evil@example.test',
    ])->assertStatus(422);
});
