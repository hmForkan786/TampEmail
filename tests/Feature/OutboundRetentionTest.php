<?php

declare(strict_types=1);

use App\Actions\Outbound\CreateOutboundRetentionHoldAction;
use App\Actions\Outbound\DeleteOutboundMessageAction;
use App\Actions\Outbound\ReleaseOutboundRetentionHoldAction;
use App\DTOs\Outbound\CreateOutboundRetentionHoldData;
use App\Enums\AttachmentScanStatus;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Enums\ValueType;
use App\Exceptions\OutboundSendException;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Outbound\OutboundMessageAccessService;
use App\Services\Outbound\OutboundMessageListingService;
use App\Services\Outbound\OutboundRetentionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
    ]);
});

/**
 * @return array{user: User, domain: Domain, inbox: Inbox, plan: Plan}
 */
function outboundRetentionContext(array $overrides = []): array
{
    $user = User::factory()->create();

    $plan = Plan::query()->create(array_merge([
        'slug' => 'retention-'.uniqid(),
        'name' => 'Retention Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ], $overrides['plan'] ?? []));

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
        'domain' => 'retain-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Retention',
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

    return compact('user', 'domain', 'inbox', 'plan');
}

/**
 * @param  array{user: User, inbox: Inbox}  $ctx
 */
function makeRetentionMessage(array $ctx, array $overrides = []): OutboundMessage
{
    return OutboundMessage::query()->create(array_merge([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'retain-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['recipient@example.test'],
        'subject' => 'Hello world',
        'text_body' => 'Body text',
        'queued_at' => now(),
    ], $overrides));
}

function retentionPlatformAdmin(): User
{
    return User::factory()->create([
        'platform_role' => PlatformRole::Admin,
        'status' => UserStatus::Active,
    ]);
}

function grantOutboundRetentionFeature(Plan $plan, int $days): void
{
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'outbound_retention_days'],
        [
            'name' => 'Outbound Retention Days',
            'value_type' => ValueType::Boolean,
            'default_value' => ['days' => 1],
            'is_active' => true,
            'display_order' => 20,
        ],
    );

    $plan->features()->syncWithoutDetaching([
        $feature->id => ['feature_value' => ['days' => $days]],
    ]);
}

// --- Config -------------------------------------------------------------

it('fails closed on invalid or zero outbound retention config values', function (): void {
    config([
        'outbound_retention.free_days' => 0,
        'outbound_retention.premium_days' => 0,
    ]);

    $ctx = outboundRetentionContext();

    expect(app(OutboundRetentionPolicy::class)->contentRetentionDays($ctx['user']))->toBe(0);
});

it('resolves free plan retention days from config by default', function (): void {
    config(['outbound_retention.free_days' => 1, 'outbound_retention.premium_days' => 30]);

    $ctx = outboundRetentionContext(['plan' => ['is_free' => true]]);

    expect(app(OutboundRetentionPolicy::class)->contentRetentionDays($ctx['user']))->toBe(1);
});

it('resolves premium plan retention days from config when no entitlement override exists', function (): void {
    config(['outbound_retention.free_days' => 1, 'outbound_retention.premium_days' => 30]);

    $ctx = outboundRetentionContext(['plan' => ['is_free' => false]]);

    expect(app(OutboundRetentionPolicy::class)->contentRetentionDays($ctx['user']))->toBe(30);
});

it('prefers a plan entitlement override over the free/premium config default', function (): void {
    config(['outbound_retention.free_days' => 1, 'outbound_retention.premium_days' => 30]);

    $ctx = outboundRetentionContext(['plan' => ['is_free' => true]]);
    grantOutboundRetentionFeature($ctx['plan'], 7);

    expect(app(OutboundRetentionPolicy::class)->contentRetentionDays($ctx['user']))->toBe(7);
});

it('changes retention days when the user changes plan', function (): void {
    config(['outbound_retention.free_days' => 1, 'outbound_retention.premium_days' => 30]);

    $ctx = outboundRetentionContext(['plan' => ['is_free' => true]]);
    $policy = app(OutboundRetentionPolicy::class);

    expect($policy->contentRetentionDays($ctx['user']))->toBe(1);

    $premiumPlan = Plan::query()->create([
        'slug' => 'retention-premium-'.uniqid(),
        'name' => 'Retention Premium Plan',
        'price_monthly' => '9.00',
        'price_yearly' => '90.00',
        'currency' => 'USD',
        'is_free' => false,
        'is_active' => true,
        'display_order' => 2,
    ]);

    Subscription::query()->where('user_id', $ctx['user']->id)->update(['status' => SubscriptionStatus::Cancelled]);
    Subscription::query()->create([
        'user_id' => $ctx['user']->id,
        'plan_id' => $premiumPlan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now(),
        'auto_renew' => true,
        'price' => '9.00',
        'currency' => 'USD',
    ]);

    expect($policy->contentRetentionDays($ctx['user']->fresh()))->toBe(30);
});

// --- User deletion --------------------------------------------------------

it('lets the owner delete their own outbound message', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx, ['state' => OutboundMessageState::Sent, 'sent_at' => now()]);

    $deleted = app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    expect($deleted->user_deleted_at)->not->toBeNull()
        ->and($deleted->state)->toBe(OutboundMessageState::Sent);
});

it('denies deletion for a non-owner', function (): void {
    $ctx = outboundRetentionContext();
    $other = outboundRetentionContext();
    $message = makeRetentionMessage($other);

    expect(fn () => app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']))
        ->toThrow(OutboundSendException::class);
});

it('rejects deleting an already-deleted message', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);

    app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    expect(fn () => app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']))
        ->toThrow(OutboundSendException::class);
});

it('cancels a still-queued message before hiding it', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx, ['state' => OutboundMessageState::Queued]);

    $deleted = app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    expect($deleted->state)->toBe(OutboundMessageState::Cancelled)
        ->and($deleted->cancelled_at)->not->toBeNull()
        ->and($deleted->user_deleted_at)->not->toBeNull();
});

it('hides a sent message without rewriting its transport state', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx, [
        'state' => OutboundMessageState::Sent,
        'sent_at' => now(),
        'provider_message_id' => 'provider-msg-keep',
    ]);

    $deleted = app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    expect($deleted->state)->toBe(OutboundMessageState::Sent)
        ->and($deleted->provider_message_id)->toBe('provider-msg-keep')
        ->and($deleted->cancelled_at)->toBeNull();
});

it('hides a delivered message and preserves delivered_at', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx, [
        'state' => OutboundMessageState::Delivered,
        'sent_at' => now()->subMinute(),
        'delivered_at' => now(),
    ]);

    $deleted = app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    expect($deleted->state)->toBe(OutboundMessageState::Delivered)
        ->and($deleted->delivered_at)->not->toBeNull();
});

it('never deletes the shared source attachment when a message is hidden', function (): void {
    $ctx = outboundRetentionContext();
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
        'original_filename' => 'report.pdf',
        'stored_filename' => 'opaque-'.bin2hex(random_bytes(4)),
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'checksum_sha256' => hash('sha256', bin2hex(random_bytes(8))),
        'storage_disk' => 'attachments',
        'storage_path' => 'outbound/'.$email->id.'/opaque',
        'scan_status' => AttachmentScanStatus::Clean,
        'is_safe' => true,
    ]);
    $message = makeRetentionMessage($ctx, [
        'source_email_id' => $email->id,
        'attachment_ids' => [$attachment->id],
    ]);

    app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    expect(Attachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

// --- Listing exclusion ----------------------------------------------------

it('excludes user-deleted messages from the owner listing service', function (): void {
    $ctx = outboundRetentionContext();
    $visible = makeRetentionMessage($ctx, ['subject' => 'Visible']);
    $hidden = makeRetentionMessage($ctx, ['subject' => 'Hidden']);

    app(DeleteOutboundMessageAction::class)->execute($hidden->id, $ctx['user']);

    $results = app(OutboundMessageListingService::class)->list($ctx['user']);

    expect($results->total())->toBe(1)
        ->and($results->items()[0]->id)->toBe($visible->id);
});

it('excludes a hidden message from OutboundMessageAccessService::findOwned', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);

    app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    $found = app(OutboundMessageAccessService::class)->findOwned($ctx['user'], $message->id);

    expect($found)->toBeNull();
});

it('denies attachment download once the outbound message is hidden', function (): void {
    $ctx = outboundRetentionContext();
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
        'original_filename' => 'report.pdf',
        'stored_filename' => 'opaque-'.bin2hex(random_bytes(4)),
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'checksum_sha256' => hash('sha256', bin2hex(random_bytes(8))),
        'storage_disk' => 'attachments',
        'storage_path' => 'outbound/'.$email->id.'/opaque',
        'scan_status' => AttachmentScanStatus::Clean,
        'is_safe' => true,
    ]);
    Storage::disk('attachments')->put($attachment->storage_path, 'binary-content');
    $message = makeRetentionMessage($ctx, [
        'source_email_id' => $email->id,
        'attachment_ids' => [$attachment->id],
    ]);

    app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);

    $this->actingAs($ctx['user'])
        ->get('/outbound-messages/'.$message->id.'/attachments/'.$attachment->id)
        ->assertNotFound();
});

// --- Legal / security hold -------------------------------------------------

it('lets a platform admin set a retention hold on a message', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);
    $admin = retentionPlatformAdmin();

    $held = app(CreateOutboundRetentionHoldAction::class)->execute(new CreateOutboundRetentionHoldData(
        messageId: $message->id,
        heldByUserId: $admin->id,
        reasonCode: 'legal_hold',
    ));

    expect($held->retention_hold_reason_code)->toBe('legal_hold')
        ->and($held->isRetentionHeld())->toBeTrue();
});

it('denies a non-admin from setting a retention hold', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);

    expect(fn () => app(CreateOutboundRetentionHoldAction::class)->execute(new CreateOutboundRetentionHoldData(
        messageId: $message->id,
        heldByUserId: $ctx['user']->id,
        reasonCode: 'legal_hold',
    )))->toThrow(AuthorizationException::class);
});

it('rejects a free-text reason code for a retention hold', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);
    $admin = retentionPlatformAdmin();

    expect(fn () => app(CreateOutboundRetentionHoldAction::class)->execute(new CreateOutboundRetentionHoldData(
        messageId: $message->id,
        heldByUserId: $admin->id,
        reasonCode: 'because I said so',
    )))->toThrow(InvalidArgumentException::class);
});

it('lets a platform admin release a retention hold', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);
    $admin = retentionPlatformAdmin();

    app(CreateOutboundRetentionHoldAction::class)->execute(new CreateOutboundRetentionHoldData(
        messageId: $message->id,
        heldByUserId: $admin->id,
        reasonCode: 'legal_hold',
    ));

    $released = app(ReleaseOutboundRetentionHoldAction::class)->execute($message->id, $admin->id);

    expect($released->retention_hold_reason_code)->toBeNull()
        ->and($released->isRetentionHeld())->toBeFalse();
});

it('does not restore user visibility when a retention hold is released', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);
    $admin = retentionPlatformAdmin();

    app(DeleteOutboundMessageAction::class)->execute($message->id, $ctx['user']);
    app(CreateOutboundRetentionHoldAction::class)->execute(new CreateOutboundRetentionHoldData(
        messageId: $message->id,
        heldByUserId: $admin->id,
        reasonCode: 'legal_hold',
    ));
    app(ReleaseOutboundRetentionHoldAction::class)->execute($message->id, $admin->id);

    $found = app(OutboundMessageAccessService::class)->findOwned($ctx['user'], $message->id);

    expect($found)->toBeNull();
});

it('treats a null retention_hold_until with a reason code as an indefinite hold', function (): void {
    $ctx = outboundRetentionContext();
    $message = makeRetentionMessage($ctx);
    $admin = retentionPlatformAdmin();

    $held = app(CreateOutboundRetentionHoldAction::class)->execute(new CreateOutboundRetentionHoldData(
        messageId: $message->id,
        heldByUserId: $admin->id,
        reasonCode: 'security_investigation',
        heldUntil: null,
    ));

    expect($held->retention_hold_until)->toBeNull()
        ->and($held->isRetentionHeld())->toBeTrue();
});
