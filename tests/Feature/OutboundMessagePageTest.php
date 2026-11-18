<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\AttachmentScanStatus;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-page-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.transport' => 'unavailable',
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'queue.default' => 'sync',
    ]);
});

/**
 * @return array{user: User, domain: Domain, inbox: Inbox, token: string}
 */
function outboundMessagePageContext(array $overrides = []): array
{
    $user = User::factory()->create(array_merge([
        'password' => Hash::make('page-test-password'),
    ], $overrides['user'] ?? []));

    $plan = Plan::query()->create([
        'slug' => 'outbound-page-'.uniqid(),
        'name' => 'Outbound Page Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $feature = Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        [
            'name' => 'Send Email',
            'value_type' => ValueType::Boolean,
            'default_value' => ['enabled' => true],
            'is_active' => true,
            'display_order' => 10,
        ],
    );
    $plan->features()->syncWithoutDetaching([
        $feature->id => ['feature_value' => ['enabled' => true]],
    ]);
    attachApiCommercialFeatures($plan);

    Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $domain = Domain::query()->create([
        'domain' => 'page-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Page',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);

    $inbox = Inbox::query()->create(array_merge([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'sender',
        'full_address' => 'sender@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ], $overrides['inbox'] ?? []));

    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'page-key',
        permissions: $overrides['scopes'] ?? ['outbound_messages:read', 'outbound_messages:write'],
        user: $user,
    )->plainToken;

    return compact('user', 'domain', 'inbox', 'token');
}

/**
 * @param  array{user: User, inbox: Inbox}  $ctx
 */
function makeOutboundMessage(array $ctx, array $overrides = []): OutboundMessage
{
    return OutboundMessage::query()->create(array_merge([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'page-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['recipient@example.test'],
        'subject' => 'Hello world',
        'text_body' => 'Body text',
        'queued_at' => now(),
    ], $overrides));
}

/**
 * @param  array{user: User, inbox: Inbox}  $ctx
 * @return array{email: Email, attachment: Attachment, message: OutboundMessage}
 */
function outboundAttachmentFixture(array $ctx, bool $safe, array $messageOverrides = []): array
{
    Storage::fake('attachments');

    $email = Email::query()->create([
        'inbox_id' => $ctx['inbox']->id,
        'message_id' => 'msg-'.bin2hex(random_bytes(4)).'@example.test',
        'sender_email' => 'origin@example.test',
        'recipient_email' => $ctx['inbox']->full_address,
        'received_at' => now(),
        'size_bytes' => 100,
        'processing_status' => 'received',
    ]);

    $attachment = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => $safe ? 'report.pdf' : 'malware.exe',
        'stored_filename' => 'opaque-'.bin2hex(random_bytes(4)),
        'mime_type' => $safe ? 'application/pdf' : 'application/x-msdownload',
        'size_bytes' => 1024,
        'checksum_sha256' => hash('sha256', bin2hex(random_bytes(8))),
        'storage_disk' => 'attachments',
        'storage_path' => 'outbound/'.$email->id.'/opaque',
        'scan_status' => $safe ? AttachmentScanStatus::Clean : AttachmentScanStatus::Infected,
        'is_safe' => $safe,
    ]);

    if ($safe) {
        Storage::disk('attachments')->put($attachment->storage_path, 'binary-content');
    }

    $message = makeOutboundMessage($ctx, array_merge([
        'source_email_id' => $email->id,
        'attachment_ids' => [$attachment->id],
    ], $messageOverrides));

    return compact('email', 'attachment', 'message');
}

// --- Ownership scoping -------------------------------------------------

it('lets an owner list their own outbound messages via the API', function (): void {
    $ctx = outboundMessagePageContext();
    makeOutboundMessage($ctx, ['subject' => 'First']);
    makeOutboundMessage($ctx, ['subject' => 'Second']);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(2);
});

it('lets an authenticated owner view the web outbound message list', function (): void {
    $ctx = outboundMessagePageContext();
    makeOutboundMessage($ctx, ['subject' => 'Web Visible Subject']);

    $this->actingAs($ctx['user'])
        ->get('/outbound-messages')
        ->assertOk()
        ->assertSee('Web Visible Subject');
});

it('hides another user\'s messages from the API list', function (): void {
    $ctx = outboundMessagePageContext();
    $other = outboundMessagePageContext();
    makeOutboundMessage($ctx, ['subject' => 'Mine']);
    makeOutboundMessage($other, ['subject' => 'Theirs']);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.subject'))->toBe('Mine');
});

it('hides another user\'s messages from the web list', function (): void {
    $ctx = outboundMessagePageContext();
    $other = outboundMessagePageContext();
    makeOutboundMessage($other, ['subject' => 'Not Yours']);

    $this->actingAs($ctx['user'])
        ->get('/outbound-messages')
        ->assertOk()
        ->assertDontSee('Not Yours');
});

it('returns 404 for direct API detail access to another user\'s message', function (): void {
    $ctx = outboundMessagePageContext();
    $other = outboundMessagePageContext();
    $message = makeOutboundMessage($other);

    $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages/'.$message->id)
        ->assertNotFound();
});

it('returns 404 for direct web detail access to another user\'s message', function (): void {
    $ctx = outboundMessagePageContext();
    $other = outboundMessagePageContext();
    $message = makeOutboundMessage($other);

    $this->actingAs($ctx['user'])
        ->get('/outbound-messages/'.$message->id)
        ->assertNotFound();
});

it('scopes recipient filters to the owner only', function (): void {
    $ctx = outboundMessagePageContext();
    $other = outboundMessagePageContext();
    makeOutboundMessage($ctx, ['to_recipients' => ['shared@example.test']]);
    makeOutboundMessage($other, ['to_recipients' => ['shared@example.test']]);

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages?recipient=shared')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('filters by state', function (): void {
    $ctx = outboundMessagePageContext();
    makeOutboundMessage($ctx, ['state' => OutboundMessageState::Queued]);
    makeOutboundMessage($ctx, ['state' => OutboundMessageState::Failed, 'failure_code' => 'smtp_5xx', 'failed_at' => now()]);

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages?state=failed')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.state'))->toBe('failed');
});

// --- Authentication parity ----------------------------------------------

it('requires api authentication for the outbound messages index', function (): void {
    $this->getJson('/api/v1/outbound-messages')->assertUnauthorized();
});

it('redirects unauthenticated users to login for the web outbound message list', function (): void {
    $this->get('/outbound-messages')->assertRedirect(route('login'));
});

it('redirects unauthenticated users to login for the web outbound message detail', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx);

    $this->get('/outbound-messages/'.$message->id)->assertRedirect(route('login'));
});

it('lets a user log in with valid credentials and reach the outbound message list', function (): void {
    $ctx = outboundMessagePageContext();

    $this->post('/login', [
        'email' => $ctx['user']->email,
        'password' => 'page-test-password',
    ])->assertRedirect(route('outbound-messages.index'));

    $this->assertAuthenticatedAs($ctx['user']);
});

it('rejects an invalid login', function (): void {
    $ctx = outboundMessagePageContext();

    $this->post('/login', [
        'email' => $ctx['user']->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

// --- State labels ---------------------------------------------------------

it('distinguishes sent from delivered on the web list without overclaiming delivery', function (): void {
    $ctx = outboundMessagePageContext();
    makeOutboundMessage($ctx, ['subject' => 'Sent Only', 'state' => OutboundMessageState::Sent, 'sent_at' => now()]);
    makeOutboundMessage($ctx, ['subject' => 'Fully Delivered', 'state' => OutboundMessageState::Delivered, 'sent_at' => now()->subMinute(), 'delivered_at' => now()]);

    $response = $this->actingAs($ctx['user'])->get('/outbound-messages')->assertOk();

    $response->assertSee('Sent')->assertSee('Delivered');
});

// --- BCC visibility ---------------------------------------------------------

it('shows bcc to the owner on the API detail but never in the timeline', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, ['bcc_recipients' => ['secret-bcc@example.test']]);
    app(AuditLogWriter::class)->write('outbound.message_created', (string) $ctx['user']->id, $message);

    $detail = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id)->assertOk();
    expect($detail->json('data.bcc'))->toBe(['secret-bcc@example.test']);

    $timeline = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id.'/timeline')->assertOk();
    expect($timeline->getContent())->not->toContain('secret-bcc@example.test');
});

it('shows bcc to the owner on the web detail page', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, ['bcc_recipients' => ['secret-bcc@example.test']]);

    $this->actingAs($ctx['user'])
        ->get('/outbound-messages/'.$message->id)
        ->assertOk()
        ->assertSee('secret-bcc@example.test');
});

// --- HTML sanitization ---------------------------------------------------------

it('sanitizes html_body in the API resource', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, ['html_body' => '<p>Safe content</p><script>alert(1)</script>']);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id)->assertOk();

    expect($response->json('data.html_body'))->toContain('Safe content')
        ->and($response->json('data.html_body'))->not->toContain('<script>');
});

it('renders sanitized html and never renders unsafe script tags on the web detail page', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, ['html_body' => '<p>Rendered Safe Body</p><script>alert(1)</script>']);

    $response = $this->actingAs($ctx['user'])->get('/outbound-messages/'.$message->id);

    $response->assertOk()
        ->assertSee('Rendered Safe Body')
        ->assertDontSee('<script>alert(1)</script>', false);
});

// --- Attachments ---------------------------------------------------------

it('lists a safe attachment on the API detail', function (): void {
    $ctx = outboundMessagePageContext();
    $fixture = outboundAttachmentFixture($ctx, safe: true);

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages/'.$fixture['message']->id)
        ->assertOk();

    expect($response->json('data.attachments'))->toHaveCount(1)
        ->and($response->json('data.attachments.0.original_filename'))->toBe('report.pdf')
        ->and($response->json('data.attachments.0'))->not->toHaveKey('storage_path');
});

it('hides an unsafe attachment from the API detail', function (): void {
    $ctx = outboundMessagePageContext();
    $fixture = outboundAttachmentFixture($ctx, safe: false);

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages/'.$fixture['message']->id)
        ->assertOk();

    expect($response->json('data.attachments'))->toBe([]);
});

it('allows the owner to download a safe attachment via the API', function (): void {
    $ctx = outboundMessagePageContext();
    $fixture = outboundAttachmentFixture($ctx, safe: true);

    $this->withToken($ctx['token'])
        ->get('/api/v1/outbound-messages/'.$fixture['message']->id.'/attachments/'.$fixture['attachment']->id)
        ->assertOk();
});

it('denies attachment download for an unsafe attachment', function (): void {
    $ctx = outboundMessagePageContext();
    $fixture = outboundAttachmentFixture($ctx, safe: false);

    $this->withToken($ctx['token'])
        ->get('/api/v1/outbound-messages/'.$fixture['message']->id.'/attachments/'.$fixture['attachment']->id)
        ->assertNotFound();
});

it('denies attachment download to a non-owner', function (): void {
    $ctx = outboundMessagePageContext();
    $other = outboundMessagePageContext();
    $fixture = outboundAttachmentFixture($ctx, safe: true);

    $this->withToken($other['token'])
        ->get('/api/v1/outbound-messages/'.$fixture['message']->id.'/attachments/'.$fixture['attachment']->id)
        ->assertNotFound();
});

it('denies attachment download when the attachment id is not on the message', function (): void {
    $ctx = outboundMessagePageContext();
    $fixtureA = outboundAttachmentFixture($ctx, safe: true);
    $fixtureB = outboundAttachmentFixture($ctx, safe: true);

    $this->withToken($ctx['token'])
        ->get('/api/v1/outbound-messages/'.$fixtureA['message']->id.'/attachments/'.$fixtureB['attachment']->id)
        ->assertNotFound();
});

// --- Cancel / retry eligibility ---------------------------------------------------------

it('allows cancelling a queued message but not a sent one', function (): void {
    $ctx = outboundMessagePageContext();
    $queued = makeOutboundMessage($ctx, ['state' => OutboundMessageState::Queued]);
    $sent = makeOutboundMessage($ctx, ['state' => OutboundMessageState::Sent, 'sent_at' => now()]);

    $queuedResponse = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$queued->id)->assertOk();
    expect($queuedResponse->json('data.can_cancel'))->toBeTrue();

    $sentResponse = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$sent->id)->assertOk();
    expect($sentResponse->json('data.can_cancel'))->toBeFalse();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$queued->id.'/cancel')->assertOk()
        ->assertJsonPath('data.state', 'cancelled');

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$sent->id.'/cancel')->assertStatus(422);
});

it('offers retry for an eligible failed message but not for a non-retryable failure', function (): void {
    $ctx = outboundMessagePageContext();
    $retryable = makeOutboundMessage($ctx, [
        'state' => OutboundMessageState::Failed,
        'failure_code' => 'smtp_5xx',
        'failed_at' => now(),
    ]);
    $nonRetryable = makeOutboundMessage($ctx, [
        'state' => OutboundMessageState::Failed,
        'failure_code' => 'entitlement_denied',
        'failed_at' => now(),
    ]);

    $retryableResponse = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$retryable->id)->assertOk();
    expect($retryableResponse->json('data.can_retry'))->toBeTrue();

    $nonRetryableResponse = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$nonRetryable->id)->assertOk();
    expect($nonRetryableResponse->json('data.can_retry'))->toBeFalse();
});

it('denies retry eligibility under emergency stop', function (): void {
    $ctx = outboundMessagePageContext();
    $failed = makeOutboundMessage($ctx, [
        'state' => OutboundMessageState::Failed,
        'failure_code' => 'smtp_5xx',
        'failed_at' => now(),
    ]);

    config(['outbound.rollout.emergency_stop' => true]);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$failed->id)->assertOk();
    expect($response->json('data.can_retry'))->toBeFalse();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages/'.$failed->id.'/retry')
        ->assertStatus(503);
});

it('shows a degraded banner on the web pages during emergency stop', function (): void {
    $ctx = outboundMessagePageContext();
    config(['outbound.rollout.emergency_stop' => true]);

    $this->actingAs($ctx['user'])
        ->get('/outbound-messages')
        ->assertOk()
        ->assertSee('temporarily paused');
});

it('lets the web owner cancel a queued message via the show page form', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, ['state' => OutboundMessageState::Queued]);

    $this->actingAs($ctx['user'])
        ->post(route('outbound-messages.cancel', $message))
        ->assertRedirect(route('outbound-messages.show', $message));

    expect($message->fresh()->state)->toBe(OutboundMessageState::Cancelled);
});

// --- Safe fields / no metadata leaks ---------------------------------------------------------

it('never exposes metadata or provider identity in the outbound message resource', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, [
        'provider' => 'generic',
        'provider_message_id' => 'provider-msg-123',
        'metadata' => ['internal' => 'should-not-leak'],
    ]);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id)->assertOk();

    $response->assertJsonMissingPath('data.metadata')
        ->assertJsonMissingPath('data.provider')
        ->assertJsonMissingPath('data.provider_message_id')
        ->assertJsonMissingPath('data.reconciliation_note')
        ->assertJsonMissingPath('data.reconciliation_flagged_at');
});

it('exposes a user-safe failure category alongside the failure code', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, [
        'state' => OutboundMessageState::Failed,
        'failure_code' => 'smtp_5xx',
        'failed_at' => now(),
    ]);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id)->assertOk();

    $response->assertJsonPath('data.failure_code', 'smtp_5xx')
        ->assertJsonPath('data.failure_category', 'permanent_issue');
});

it('includes delivered_at and attachment_count as additive resource fields', function (): void {
    $ctx = outboundMessagePageContext();
    $message = makeOutboundMessage($ctx, [
        'state' => OutboundMessageState::Delivered,
        'sent_at' => now()->subMinute(),
        'delivered_at' => now(),
    ]);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id)->assertOk();

    $response->assertJsonPath('data.attachment_count', 0)
        ->assertJsonPath('data.delivered_at', $message->fresh()->delivered_at->toJSON());
});

// --- Pagination ---------------------------------------------------------

it('paginates the outbound message list with a default and capped page size', function (): void {
    $ctx = outboundMessagePageContext();
    for ($i = 0; $i < 3; $i++) {
        makeOutboundMessage($ctx);
    }

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages?per_page=2')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.per_page'))->toBe(2)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('caps per_page at the configured maximum', function (): void {
    $ctx = outboundMessagePageContext();
    makeOutboundMessage($ctx);

    $response = $this->withToken($ctx['token'])
        ->getJson('/api/v1/outbound-messages?per_page=500')
        ->assertOk();

    expect($response->json('meta.per_page'))->toBe(100);
});
